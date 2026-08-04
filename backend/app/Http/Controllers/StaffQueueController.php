<?php

namespace App\Http\Controllers;

use App\Models\QueueEntry;
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
                        fn ($query) =>
                        $query->where('is_active', true),
                    ),
            ],
        ]);

        $today = now()->toDateString();

        $result = DB::transaction(
            function () use ($validated, $today): array {
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
                    ->orderBy('queue_number')
                    ->lockForUpdate()
                    ->first();

                if (! $nextEntry) {
                    return [
                        'type' => 'empty',
                    ];
                }

                $nextEntry->update([
                    'status' => 'called',
                    'called_at' => now(),
                ]);

                return [
                    'type' => 'called',
                    'entry' => $nextEntry,
                ];
            },
        );

        if ($result['type'] === 'active') {
            return response()->json([
                'message' =>
                    'Complete the currently called customer first.',
            ], 409);
        }

        if ($result['type'] === 'empty') {
            return response()->json([
                'message' =>
                    'There are no waiting customers for this service.',
            ], 404);
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
        if (
            $queueEntry->queue_date->toDateString()
            !== now()->toDateString()
        ) {
            return response()->json([
                'message' =>
                    'This queue entry is not from today.',
            ], 422);
        }

        if (
            ! in_array(
                $queueEntry->status,
                ['called', 'serving'],
                true,
            )
        ) {
            return response()->json([
                'message' =>
                    'Only a called or serving customer can be completed.',
            ], 422);
        }

        $queueEntry->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $queueEntry->load([
            'user:id,name,email',
            'service:id,name,average_service_minutes',
        ]);

        return response()->json([
            'message' =>
                'The customer has been marked as completed.',

            'queue_entry' => $queueEntry,
        ]);
    }
}