<?php

namespace App\Http\Controllers;

use App\Models\QueueEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminQueueHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'service_id' => ['sometimes', 'required', 'integer', 'exists:services,id'],
            'status' => ['sometimes', 'required', Rule::in(['completed', 'cancelled', 'skipped'])],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $date = $validated['date'] ?? now()->toDateString();
        $query = QueueEntry::query()->whereDate('queue_date', $date);
        if (isset($validated['service_id'])) {
            $query->where('service_id', $validated['service_id']);
        }

        $counts = (clone $query)->toBase()->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total', 'status');
        $summary = [];
        foreach (['waiting', 'called', 'serving', 'completed', 'cancelled', 'skipped'] as $status) {
            $summary[$status] = (int) ($counts[$status] ?? 0);
        }
        $summary['total'] = array_sum($summary);
        $summary['active'] = $summary['waiting'] + $summary['called'] + $summary['serving'];

        $entries = $query->whereIn('status', ['completed', 'cancelled', 'skipped']);
        if (isset($validated['status'])) {
            $entries->where('status', $validated['status']);
        }
        $entries = $entries->with(['user:id,name', 'service:id,name,is_active'])
            ->orderByDesc('joined_at')->orderByDesc('id')->paginate(20);
        $entries->through(fn (QueueEntry $entry): array => [
            'id' => $entry->id,
            'queue_number' => $entry->queue_number,
            'queue_date' => $entry->queue_date->toDateString(),
            'status' => $entry->status,
            'customer_name' => $entry->user?->name,
            'service' => $entry->service,
            'joined_at' => $entry->joined_at?->toIso8601String(),
            'called_at' => $entry->called_at?->toIso8601String(),
            'completed_at' => $entry->completed_at?->toIso8601String(),
        ]);

        return response()->json([
            'date' => $date,
            'summary' => $summary,
            'entries' => $entries,
        ]);
    }
}
