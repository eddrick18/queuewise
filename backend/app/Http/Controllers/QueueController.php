<?php

namespace App\Http\Controllers;

use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QueueController extends Controller
{
    /**
     * Return the customer's latest queue record for today.
     */
    public function current(Request $request): JsonResponse
    {
        $queueEntry = QueueEntry::query()
            ->with('service:id,name,average_service_minutes')
            ->where('user_id', $request->user()->id)
            ->whereDate('queue_date', now()->toDateString())
            ->latest('joined_at')
            ->latest('id')
            ->first();

        return response()->json([
            'queue_entry' => $queueEntry
                ? $this->formatQueueEntry($queueEntry)
                : null,
        ]);
    }

    /**
     * Add the authenticated customer to a service queue.
     */
    public function join(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => [
                'required',
                'integer',

                Rule::exists('services', 'id')
                    ->where(
                        fn ($query) => $query->where('is_active', true)
                    ),
            ],
        ]);

        $user = $request->user();
        $today = now()->toDateString();

        /*
         * Prevent a customer from having more than one
         * active queue entry.
         */
        $existingQueue = QueueEntry::query()
            ->with('service:id,name,average_service_minutes')
            ->where('user_id', $user->id)
            ->whereDate('queue_date', $today)
            ->whereIn('status', [
                'waiting',
                'called',
                'serving',
            ])
            ->first();

        if ($existingQueue) {
            return response()->json([
                'message' => 'You already have an active queue entry.',

                'queue_entry' => $this->formatQueueEntry($existingQueue),
            ], 409);
        }

        $queueEntry = DB::transaction(
            function () use (
                $validated,
                $user,
                $today,
            ): QueueEntry {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_if(QueueEntry::where('user_id', $user->id)->whereDate('queue_date', $today)
                    ->whereIn('status', ['waiting', 'called', 'serving'])->exists(), 409, 'You already have an active queue entry.');
                $service = Service::query()
                    ->whereKey($validated['service_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lastQueueNumber = QueueEntry::query()
                    ->where('service_id', $service->id)
                    ->whereDate('queue_date', $today)
                    ->max('queue_number');

                $nextQueueNumber =
                    ((int) $lastQueueNumber) + 1;

                return QueueEntry::create([
                    'user_id' => $user->id,
                    'service_id' => $service->id,
                    'queue_number' => $nextQueueNumber,
                    'queue_date' => $today,
                    'status' => 'waiting',
                    'joined_at' => now(),
                ]);
            },
        );

        $queueEntry->load(
            'service:id,name,average_service_minutes',
        );

        return response()->json([
            'message' => 'You successfully joined the queue.',

            'queue_entry' => $this->formatQueueEntry($queueEntry),
        ], 201);
    }

    /**
     * Cancel a queue entry owned by the authenticated customer.
     */
    public function cancel(
        Request $request,
        QueueEntry $queueEntry,
    ): JsonResponse {
        /*
         * Prevent customers from cancelling another
         * customer's queue entry.
         */
        if ($queueEntry->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You are not allowed to cancel this queue entry.',
            ], 403);
        }

        /*
         * Only today's queue record may be cancelled.
         */
        if (
            $queueEntry->queue_date->toDateString()
            !== now()->toDateString()
        ) {
            return response()->json([
                'message' => 'Only today\'s queue entry can be cancelled.',
            ], 422);
        }

        /*
         * A customer may cancel only while waiting.
         */
        $updated = QueueEntry::query()
            ->whereKey($queueEntry->id)
            ->where('status', 'waiting')
            ->update(['status' => 'cancelled']);

        if ($updated === 0) {
            return response()->json([
                'message' => 'Only a waiting queue entry can be cancelled.',
            ], 422);
        }

        $queueEntry->refresh();

        $queueEntry->load(
            'service:id,name,average_service_minutes',
        );

        return response()->json([
            'message' => 'Your queue entry has been cancelled.',

            'queue_entry' => $this->formatQueueEntry($queueEntry),
        ]);
    }

    /**
     * Convert a QueueEntry model into frontend-friendly data.
     */
    private function formatQueueEntry(
        QueueEntry $queueEntry,
    ): array {
        $queueEntry->loadMissing(
            'service:id,name,average_service_minutes',
        );

        $active = QueueEntry::query()
            ->where('service_id', $queueEntry->service_id)
            ->whereDate(
                'queue_date',
                $queueEntry->queue_date,
            )
            ->where('id', '!=', $queueEntry->id);
        $peopleAhead = 0;
        if ($queueEntry->status === 'waiting') {
            $peopleAhead = (clone $active)->whereIn('status', ['called', 'serving'])->count();
            $waiting = (clone $active)->where('status', 'waiting')
                ->where(fn ($query) => $query->whereNull('priority_at')->orWhere('priority_at', '<=', now()));
            if ($queueEntry->priority_at && $queueEntry->priority_at->lte(now())) {
                $waiting->whereNotNull('priority_at')->where(function ($query) use ($queueEntry) {
                    $query->where('priority_at', '<', $queueEntry->priority_at)
                        ->orWhere(fn ($same) => $same->where('priority_at', $queueEntry->priority_at)->where('queue_number', '<', $queueEntry->queue_number));
                });
            } else {
                $waiting->where(fn ($query) => $query->whereNotNull('priority_at')->orWhere('queue_number', '<', $queueEntry->queue_number));
            }
            $peopleAhead += $waiting->count();
        }
        $awaitingTime = $queueEntry->status === 'waiting' && $queueEntry->priority_at?->isFuture();

        $averageMinutes =
            $queueEntry
                ->service
                ->average_service_minutes;

        return [
            'id' => $queueEntry->id,

            'queue_number' => $queueEntry->queue_number,

            'queue_date' => $queueEntry
                ->queue_date
                ->toDateString(),

            'status' => $queueEntry->status,

            'joined_at' => $queueEntry
                ->joined_at
                ?->toISOString(),

            'called_at' => $queueEntry
                ->called_at
                ?->toISOString(),

            'completed_at' => $queueEntry
                ->completed_at
                ?->toISOString(),

            'priority_at' => $queueEntry->priority_at?->toISOString(),
            'people_ahead' => $awaitingTime ? null : $peopleAhead,

            'estimated_wait_minutes' => $awaitingTime ? null : $peopleAhead * $averageMinutes,

            'service' => [
                'id' => $queueEntry->service->id,

                'name' => $queueEntry->service->name,

                'average_service_minutes' => $averageMinutes,
            ],
        ];
    }
}
