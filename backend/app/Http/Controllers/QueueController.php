<?php

namespace App\Http\Controllers;

use App\Models\QueueEntry;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QueueController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $queueEntry = QueueEntry::query()
            ->with('service:id,name,average_service_minutes')
            ->where('user_id', $request->user()->id)
            ->whereDate('queue_date', now()->toDateString())
            ->latest('joined_at')
            ->first();

        return response()->json([
            'queue_entry' => $queueEntry
                ? $this->formatQueueEntry($queueEntry)
                : null,
    ]);
    }

    public function join(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => [
                'required',
                'integer',

                Rule::exists('services', 'id')
                    ->where(
                        fn ($query) =>
                        $query->where('is_active', true)
                    ),
            ],
        ]);

        $user = $request->user();
        $today = now()->toDateString();

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
                'message' =>
                    'You already have an active queue entry.',
                'queue_entry' =>
                    $this->formatQueueEntry($existingQueue),
            ], 409);
        }

        $queueEntry = DB::transaction(
            function () use (
                $validated,
                $user,
                $today,
            ): QueueEntry {
                /*
                 * Lock the selected service while generating
                 * its next queue number.
                 */
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
            'message' =>
                'You successfully joined the queue.',
            'queue_entry' =>
                $this->formatQueueEntry($queueEntry),
        ], 201);
    }

    private function formatQueueEntry(
        QueueEntry $queueEntry,
    ): array {
        $queueEntry->loadMissing(
            'service:id,name,average_service_minutes',
        );

        $peopleAhead = QueueEntry::query()
            ->where('service_id', $queueEntry->service_id)
            ->whereDate(
                'queue_date',
                $queueEntry->queue_date,
            )
            ->whereIn('status', [
                'waiting',
                'called',
                'serving',
            ])
            ->where(
                'queue_number',
                '<',
                $queueEntry->queue_number,
            )
            ->count();

        $averageMinutes =
            $queueEntry
                ->service
                ->average_service_minutes;

        return [
            'id' => $queueEntry->id,
            'queue_number' =>
                $queueEntry->queue_number,

            'queue_date' =>
                $queueEntry
                    ->queue_date
                    ->toDateString(),

            'status' => $queueEntry->status,

            'joined_at' =>
                $queueEntry
                    ->joined_at
                    ?->toISOString(),

            'called_at' =>
                $queueEntry
                    ->called_at
                    ?->toISOString(),

            'completed_at' =>
                $queueEntry
                    ->completed_at
                    ?->toISOString(),

            'people_ahead' => $peopleAhead,

            'estimated_wait_minutes' =>
                $peopleAhead * $averageMinutes,

            'service' => [
                'id' => $queueEntry->service->id,
                'name' =>
                    $queueEntry->service->name,

                'average_service_minutes' =>
                    $averageMinutes,
            ],
        ];
    }
}