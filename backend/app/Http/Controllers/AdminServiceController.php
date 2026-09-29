<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminServiceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'services' => Service::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $service = Service::create($validated);

        return response()->json([
            'message' => 'Service created successfully.',
            'service' => $service->refresh(),
        ], 201);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $validated = $request->validate($this->rules($service));

        return DB::transaction(function () use ($service, $validated): JsonResponse {
            $service = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('is_active', $validated) && ! (bool) $validated['is_active']) {
                $hasActiveQueue = $service->queueEntries()
                    ->whereDate('queue_date', now()->toDateString())
                    ->whereIn('status', ['waiting', 'called', 'serving'])
                    ->exists();

                if ($hasActiveQueue) {
                    return response()->json([
                        'message' => 'Finish or cancel today\'s active queues before deactivating this service.',
                    ], 409);
                }
            }

            $service->update($validated);

            return response()->json([
                'message' => 'Service updated successfully.',
                'service' => $service,
            ]);
        });
    }

    private function rules(?Service $service = null): array
    {
        $presence = $service ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$presence, 'string', 'max:255', Rule::unique('services', 'name')->ignore($service?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'average_service_minutes' => [...$presence, 'integer', 'min:1', 'max:1440'],
            'is_active' => [...$presence, 'boolean'],
        ];
    }
}
