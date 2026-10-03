<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    private const TIMEZONE = 'Asia/Manila';

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['date' => ['sometimes', 'required', 'date_format:Y-m-d']]);
        $date = $validated['date'] ?? now(self::TIMEZONE)->toDateString();
        $start = CarbonImmutable::parse($date, self::TIMEZONE)->utc();
        $query = Appointment::query()->with(['service:id,name', 'user:id,name', 'queueEntry:id,status,queue_number'])
            ->where('scheduled_at', '>=', $start)->where('scheduled_at', '<', $start->addDay());
        if ($request->user()->role === 'customer') {
            $query->where('user_id', $request->user()->id);
        }

        return response()->json(['date' => $date, 'appointments' => $query->orderBy('scheduled_at')->orderBy('id')->get()]);
    }

    public function upcoming(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000000']]);
        $start = CarbonImmutable::now(self::TIMEZONE)->startOfDay()->utc();
        $end = $start->addDay();
        $appointments = Appointment::query()->with(['service:id,name', 'user:id,name', 'queueEntry:id,status,queue_number'])
            ->where('scheduled_at', '>=', $start)
            ->where(fn ($query) => $query->where('scheduled_at', '<', $end)->orWhere('status', 'booked'))
            ->orderBy('scheduled_at')->orderBy('id');
        if ($request->user()->role === 'customer') {
            $appointments->where('user_id', $request->user()->id);
        }
        $appointments = $appointments->paginate(20);

        return response()->json(['appointments' => $appointments]);
    }

    public function slots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
        ]);
        $service = Service::findOrFail($validated['service_id']);
        abort_unless($service->is_active, 422, 'This service is inactive.');
        $day = $this->bookingDay($validated['date']);
        $occupied = Appointment::query()->where('service_id', $service->id)
            ->where('reserved_slot', '>=', $day->utc())->where('reserved_slot', '<', $day->addDay()->utc())
            ->pluck('reserved_slot')->map(fn ($slot) => $slot->format('Y-m-d H:i:s'))->all();
        $slots = [];
        for ($time = $day->setTime(8, 0); $time->hour < 17; $time = $time->addMinutes(30)) {
            if ($time->isFuture() && ! in_array($time->utc()->format('Y-m-d H:i:s'), $occupied, true)) {
                $slots[] = $time->format('H:i');
            }
        }

        return response()->json(['slots' => $slots]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
        ]);
        $day = $this->bookingDay($validated['date']);
        $time = CarbonImmutable::parse($day->toDateString().' '.$validated['time'], self::TIMEZONE);
        if ($time->hour < 8 || $time->hour >= 17 || ! in_array($time->minute, [0, 30], true) || ! $time->isFuture()) {
            throw ValidationException::withMessages(['time' => 'Choose a future 30-minute slot between 8 AM and 5 PM.']);
        }
        $appointment = DB::transaction(function () use ($request, $validated, $time): Appointment {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $service = Service::whereKey($validated['service_id'])->lockForUpdate()->firstOrFail();
            abort_unless($service->is_active, 422, 'This service is inactive.');
            abort_if(Appointment::where('user_id', $request->user()->id)->where('reserved_slot', $time->utc())->exists(), 409, 'You already have an appointment at this time.');
            abort_if(Appointment::where('service_id', $service->id)->where('reserved_slot', $time->utc())->exists(), 409, 'This slot was just booked. Choose another time.');

            return Appointment::create([
                'user_id' => $request->user()->id, 'service_id' => $service->id,
                'scheduled_at' => $time->utc(), 'reserved_slot' => $time->utc(), 'status' => 'booked',
            ]);
        }, 3);

        return response()->json(['appointment' => $appointment], 201);
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->user_id === $request->user()->id, 403);
        $updated = Appointment::whereKey($appointment->id)->where('status', 'booked')
            ->update(['status' => 'cancelled', 'reserved_slot' => null]);
        abort_unless($updated, 409, 'Only an appointment that has not been checked in can be cancelled.');

        return response()->json(['message' => 'Appointment cancelled.']);
    }

    public function reschedule(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->user_id === $request->user()->id, 403);
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ]);
        $day = $this->bookingDay($validated['date']);
        $time = CarbonImmutable::parse($day->toDateString().' '.$validated['time'], self::TIMEZONE)->utc();
        $local = $time->setTimezone(self::TIMEZONE);
        if ($local->hour < 8 || $local->hour >= 17 || ! in_array($local->minute, [0, 30], true) || ! $time->isFuture()) {
            throw ValidationException::withMessages(['time' => 'Choose a future 30-minute slot between 8 AM and 5 PM.']);
        }
        $appointment = DB::transaction(function () use ($appointment, $time): Appointment {
            User::whereKey($appointment->user_id)->lockForUpdate()->firstOrFail();
            $service = Service::whereKey($appointment->service_id)->lockForUpdate()->firstOrFail();
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            abort_unless($appointment->status === 'booked' && $appointment->scheduled_at->isFuture(), 409, 'Only future appointments awaiting check-in can be rescheduled.');
            abort_unless($service->is_active, 409, 'This service is inactive.');
            $conflicts = Appointment::where('id', '!=', $appointment->id)->where('reserved_slot', $time);
            abort_if((clone $conflicts)->where('user_id', $appointment->user_id)->exists(), 409, 'You already have an appointment at this time.');
            abort_if((clone $conflicts)->where('service_id', $service->id)->exists(), 409, 'This slot was just booked. Your original appointment has been kept.');
            $appointment->update(['scheduled_at' => $time, 'reserved_slot' => $time]);

            return $appointment;
        }, 3);

        return response()->json(['appointment' => $appointment]);
    }

    public function noShow(Appointment $appointment): JsonResponse
    {
        $updated = Appointment::whereKey($appointment->id)->where('status', 'booked')
            ->where('scheduled_at', '<=', now()->subMinutes(30))
            ->update(['status' => 'no_show', 'reserved_slot' => null]);
        abort_unless($updated, 409, 'Only an unchecked-in appointment whose 30-minute slot has ended can be marked as a no-show.');

        return response()->json(['message' => 'Appointment marked as no-show.']);
    }

    public function history(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000000']]);
        $query = Appointment::query()->with(['service:id,name', 'user:id,name', 'queueEntry:id,status,queue_number']);
        if ($request->user()->role === 'customer') {
            $query->where('user_id', $request->user()->id);
        }
        $query->where(function ($query) {
            $query->whereIn('status', ['cancelled', 'no_show'])
                ->orWhereHas('queueEntry', fn ($queue) => $queue->whereIn('status', ['completed', 'cancelled', 'skipped']))
                ->orWhere(fn ($missed) => $missed->where('status', 'booked')->where('scheduled_at', '<=', now()->subMinutes(30)));
        });

        return response()->json(['appointments' => $query->orderByDesc('scheduled_at')->orderByDesc('id')->paginate(20)]);
    }

    public function checkIn(Appointment $appointment): JsonResponse
    {
        $entry = DB::transaction(function () use ($appointment): QueueEntry {
            $user = User::whereKey($appointment->user_id)->lockForUpdate()->firstOrFail();
            $service = Service::whereKey($appointment->service_id)->lockForUpdate()->firstOrFail();
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && $service->is_active, 409, 'The customer or service is inactive.');
            abort_unless($appointment->status === 'booked', 409, 'This appointment is no longer awaiting check-in.');
            $localNow = now(self::TIMEZONE);
            abort_unless($appointment->scheduled_at->setTimezone(self::TIMEZONE)->toDateString() === $localNow->toDateString()
                && $localNow->hour >= 8 && $localNow->hour < 17, 422, 'Check-in is available on the appointment day, between 8 AM and 5 PM Philippine time.');
            $today = now()->toDateString();
            abort_if(QueueEntry::where('user_id', $user->id)->whereDate('queue_date', $today)
                ->whereIn('status', ['waiting', 'called', 'serving'])->exists(), 409, 'The customer already has an active queue entry.');
            $entry = QueueEntry::create([
                'user_id' => $user->id, 'service_id' => $service->id,
                'queue_date' => $today,
                'queue_number' => (int) QueueEntry::where('service_id', $service->id)->whereDate('queue_date', $today)->max('queue_number') + 1,
                'status' => 'waiting', 'joined_at' => now(), 'priority_at' => $appointment->scheduled_at,
            ]);
            $appointment->update(['status' => 'checked_in', 'queue_entry_id' => $entry->id]);

            return $entry;
        }, 3);

        return response()->json(['queue_entry' => $entry], 201);
    }

    private function bookingDay(string $date): CarbonImmutable
    {
        $day = CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        if ($day->isWeekend() || $day->lt($today) || $day->gt($today->addDays(30))) {
            throw ValidationException::withMessages(['date' => 'Choose a weekday within the next 30 days.']);
        }

        return $day;
    }
}
