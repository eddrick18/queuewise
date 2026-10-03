<?php

namespace App\Http\Controllers;

use App\Models\QueueEntry;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StaffQueueController extends Controller
{
    public function index(): JsonResponse
    {
        $today = now()->toDateString();

        $queueEntries = QueueEntry::query()
            ->with([
                'user:id,name,email',
                'service:id,name,average_service_minutes',
            ])
            ->whereDate('queue_date', $today)
            ->whereIn('status', [
                'waiting',
                'called',
                'serving',
            ])
            ->orderBy('service_id')
            ->orderBy('queue_number')
            ->get();

        return response()->json([
            'queue_entries' => $queueEntries,
        ]);
    }

    public function callNext(
        Request $request,
    ): JsonResponse {
        $validated = $request->validate([
            'service_id' => [
                'required',
                'integer',

                Rule::exists('services', 'id')
                    ->where(
                        fn ($query) => $query->where('is_active', true),
                    ),
            ],
        ]);

        $today = now()->toDateString();

        $result = DB::transaction(
            function () use ($validated, $today): array {
                Service::query()
                    ->whereKey($validated['service_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $activeEntry = QueueEntry::query()
                    ->where(
                        'service_id',
                        $validated['service_id'],
                    )
                    ->whereDate('queue_date', $today)
                    ->whereIn('status', [
                        'called',
                        'serving',
                    ])
                    ->lockForUpdate()
                    ->first();

                if ($activeEntry) {
                    return [
                        'type' => 'active',
                        'entry' => $activeEntry,
                    ];
                }

                $nextEntry = QueueEntry::query()
                    ->where(
                        'service_id',
                        $validated['service_id'],
                    )
                    ->whereDate('queue_date', $today)
                    ->where('status', 'waiting')
                    ->where(fn ($query) => $query->whereNull('priority_at')->orWhere('priority_at', '<=', now()))
                    ->orderByRaw('CASE WHEN priority_at IS NOT NULL THEN 0 ELSE 1 END')
                    ->orderBy('priority_at')
                    ->orderBy('queue_number')
                    ->lockForUpdate()
                    ->first();

                if (! $nextEntry) {
                    return [
                        'type' => 'empty',
                    ];
                }

                $updated = QueueEntry::query()
                    ->whereKey($nextEntry->id)
                    ->where('status', 'waiting')
                    ->update([
                        'status' => 'called',
                        'called_at' => now(),
                    ]);

                if ($updated === 0) {
                    return ['type' => 'changed'];
                }

                $nextEntry->refresh();

                return [
                    'type' => 'called',
                    'entry' => $nextEntry,
                ];
            },
        );

        if ($result['type'] === 'active') {
            return response()->json([
                'message' => 'Complete or skip the current customer first.',
            ], 409);
        }

        if ($result['type'] === 'empty') {
            return response()->json([
                'message' => 'No customers are ready to be called. Early appointments must wait until their booked time.',
            ], 404);
        }

        if ($result['type'] === 'changed') {
            return response()->json([
                'message' => 'The queue changed. Please call the next customer again.',
            ], 409);
        }

        $queueEntry = $result['entry'];

        $queueEntry->load([
            'user:id,name,email',
            'service:id,name,average_service_minutes',
        ]);

        return response()->json([
            'message' => 'The next customer has been called.',
            'queue_entry' => $queueEntry,
        ]);
    }

    public function complete(
        QueueEntry $queueEntry,
    ): JsonResponse {
        return $this->transition($queueEntry, 'serving', 'completed');
    }

    public function serve(QueueEntry $queueEntry): JsonResponse
    {
        return $this->transition($queueEntry, 'called', 'serving');
    }

    public function skip(QueueEntry $queueEntry): JsonResponse
    {
        return $this->transition($queueEntry, 'called', 'skipped');
    }

    private function transition(
        QueueEntry $queueEntry,
        string $fromStatus,
        string $toStatus,
    ): JsonResponse {
        if (
            $queueEntry->queue_date->toDateString()
            !== now()->toDateString()
        ) {
            return response()->json([
                'message' => 'This queue entry is not from today.',
            ], 422);
        }

        $values = ['status' => $toStatus];

        if ($toStatus === 'completed') {
            $values['completed_at'] = now();
        }

        $updated = QueueEntry::query()
            ->whereKey($queueEntry->id)
            ->where('status', $fromStatus)
            ->update($values);

        if ($updated === 0) {
            return response()->json([
                'message' => "Only a {$fromStatus} customer can be marked as {$toStatus}.",
            ], 422);
        }

        $queueEntry->refresh();

        $queueEntry->load([
            'user:id,name,email',
            'service:id,name,average_service_minutes',
        ]);

        return response()->json([
            'message' => "The customer has been marked as {$toStatus}.",

            'queue_entry' => $queueEntry,
        ]);
    }
}
