<?php

namespace Tests\Feature;

use App\Http\Controllers\QueueController;
use App\Http\Controllers\StaffQueueController;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueueLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->service = Service::create([
            'name' => 'Queue lifecycle test',
            'average_service_minutes' => 10,
            'is_active' => true,
        ]);
    }

    public function test_customer_can_log_in_join_cancel_and_rejoin_without_a_stale_active_queue(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->postJson('/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertOk();
        $this->assertAuthenticatedAs($customer);

        $entryId = $this->postJson('/api/queue/join', [
            'service_id' => $this->service->id,
        ])->assertCreated()->assertJsonPath('queue_entry.status', 'waiting')
            ->json('queue_entry.id');

        $this->patchJson("/api/queue/{$entryId}/cancel")
            ->assertOk()->assertJsonPath('queue_entry.status', 'cancelled');
        $this->getJson('/api/queue/current')
            ->assertOk()->assertJsonPath('queue_entry.status', 'cancelled');
        $this->assertSame('cancelled', QueueEntry::findOrFail($entryId)->status);
        $this->assertSame(0, $customer->queueEntries()
            ->whereIn('status', ['waiting', 'called', 'serving'])->count());

        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->getJson('/api/staff/queue')->assertOk()->assertJsonCount(0, 'queue_entries');

        $newEntryId = $this->actingAs($customer)->postJson('/api/queue/join', [
            'service_id' => $this->service->id,
        ])->assertCreated()->assertJsonPath('queue_entry.queue_number', 2)
            ->json('queue_entry.id');

        $this->getJson('/api/queue/current')->assertOk()
            ->assertJsonPath('queue_entry.id', $newEntryId)
            ->assertJsonPath('queue_entry.status', 'waiting');
    }

    public function test_customer_cannot_cancel_another_customers_entry(): void
    {
        $entry = $this->createEntry();

        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->patchJson("/api/queue/{$entry->id}/cancel")->assertForbidden();
        $this->assertSame('waiting', $entry->fresh()->status);
    }

    #[DataProvider('nonWaitingStatuses')]
    public function test_customer_cannot_cancel_a_non_waiting_entry(string $status): void
    {
        $entry = $this->createEntry($status);

        $this->actingAs($entry->user)->patchJson("/api/queue/{$entry->id}/cancel")
            ->assertUnprocessable();
        $this->assertSame($status, $entry->fresh()->status);
    }

    public static function nonWaitingStatuses(): array
    {
        return array_map(fn (string $status): array => [$status], [
            'called', 'serving', 'completed', 'cancelled', 'skipped',
        ]);
    }

    public function test_customer_cannot_cancel_yesterdays_queue(): void
    {
        $entry = $this->createEntry();
        $entry->update(['queue_date' => now()->subDay()->toDateString()]);

        $this->actingAs($entry->user)->patchJson("/api/queue/{$entry->id}/cancel")
            ->assertUnprocessable();
        $this->getJson('/api/queue/current')->assertJsonPath('queue_entry', null);
        $this->assertSame('waiting', $entry->fresh()->status);
    }

    public function test_cancellation_does_not_overwrite_a_call_after_route_binding(): void
    {
        $staleEntry = $this->createEntry();
        $request = Request::create('/api/queue/'.$staleEntry->id.'/cancel', 'PATCH');
        $request->setUserResolver(fn (): User => $staleEntry->user);
        QueueEntry::whereKey($staleEntry->id)->update(['status' => 'called']);

        $response = app(QueueController::class)->cancel($request, $staleEntry);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('called', $staleEntry->fresh()->status);
    }

    #[DataProvider('staffRoles')]
    public function test_staff_lifecycle_and_customer_visibility(string $role): void
    {
        $entry = $this->createEntry();
        $staff = User::factory()->create(['role' => $role]);

        $this->actingAs($staff)->postJson('/api/staff/queue/call-next', [
            'service_id' => $this->service->id,
        ])->assertOk()->assertJsonPath('queue_entry.id', $entry->id)
            ->assertJsonPath('queue_entry.status', 'called');
        $this->assertNotNull($entry->fresh()->called_at);

        $this->actingAs($entry->user)->getJson('/api/queue/current')
            ->assertJsonPath('queue_entry.status', 'called');

        $this->actingAs($staff)->patchJson("/api/staff/queue/{$entry->id}/serve")
            ->assertOk()->assertJsonPath('queue_entry.status', 'serving');
        $this->actingAs($entry->user)->getJson('/api/queue/current')
            ->assertJsonPath('queue_entry.status', 'serving');

        $this->actingAs($staff)->patchJson("/api/staff/queue/{$entry->id}/complete")
            ->assertOk()->assertJsonPath('queue_entry.status', 'completed');
        $this->assertNotNull($entry->fresh()->completed_at);
        $this->getJson('/api/staff/queue')->assertJsonCount(0, 'queue_entries');
        $this->actingAs($entry->user)->getJson('/api/queue/current')
            ->assertJsonPath('queue_entry.status', 'completed');
    }

    public static function staffRoles(): array
    {
        return [['staff'], ['admin']];
    }

    public function test_skipping_a_called_customer_frees_the_service_for_the_next_customer(): void
    {
        $cancelled = $this->createEntry('cancelled');
        $first = $this->createEntry('waiting', 2);
        $second = $this->createEntry('waiting', 3);
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)->postJson('/api/staff/queue/call-next', [
            'service_id' => $this->service->id,
        ])->assertOk()->assertJsonPath('queue_entry.id', $first->id);
        $this->postJson('/api/staff/queue/call-next', [
            'service_id' => $this->service->id,
        ])->assertConflict();
        $this->patchJson("/api/staff/queue/{$first->id}/skip")
            ->assertOk()->assertJsonPath('queue_entry.status', 'skipped');
        $this->getJson('/api/staff/queue')->assertJsonCount(1, 'queue_entries')
            ->assertJsonPath('queue_entries.0.id', $second->id);
        $this->postJson('/api/staff/queue/call-next', [
            'service_id' => $this->service->id,
        ])->assertOk()->assertJsonPath('queue_entry.id', $second->id);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertNull($first->fresh()->completed_at);

        $this->actingAs($first->user)->getJson('/api/queue/current')
            ->assertJsonPath('queue_entry.status', 'skipped');
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_staff_transitions_leave_the_entry_unchanged(string $status, string $action): void
    {
        $entry = $this->createEntry($status);

        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->patchJson("/api/staff/queue/{$entry->id}/{$action}")
            ->assertUnprocessable();
        $this->assertSame($status, $entry->fresh()->status);
    }

    public static function invalidTransitions(): array
    {
        $cases = [];
        foreach (['waiting', 'called', 'serving', 'completed', 'cancelled', 'skipped'] as $status) {
            foreach (['serve' => 'called', 'skip' => 'called', 'complete' => 'serving'] as $action => $required) {
                if ($status !== $required) {
                    $cases["{$status} to {$action}"] = [$status, $action];
                }
            }
        }

        return $cases;
    }

    public function test_staff_actions_reject_old_entries_and_customers(): void
    {
        $entry = $this->createEntry('called');
        $entry->update(['queue_date' => now()->subDay()->toDateString()]);

        foreach (['serve', 'skip', 'complete'] as $action) {
            $this->actingAs($entry->user)->patchJson("/api/staff/queue/{$entry->id}/{$action}")
                ->assertForbidden();
            $this->actingAs(User::factory()->create(['role' => 'staff']))
                ->patchJson("/api/staff/queue/{$entry->id}/{$action}")->assertUnprocessable();
        }
        $this->actingAs($entry->user)->postJson('/api/staff/queue/call-next', [
            'service_id' => $this->service->id,
        ])->assertForbidden();
        $this->getJson('/api/staff/queue')->assertForbidden();
    }

    public function test_serving_does_not_overwrite_a_skip_after_route_binding(): void
    {
        $staleEntry = $this->createEntry('called');
        QueueEntry::whereKey($staleEntry->id)->update(['status' => 'skipped']);

        $response = app(StaffQueueController::class)->serve($staleEntry);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('skipped', $staleEntry->fresh()->status);
    }

    public function test_queue_endpoints_require_authentication(): void
    {
        $entry = $this->createEntry();

        $this->getJson('/api/queue/current')->assertUnauthorized();
        $this->postJson('/api/queue/join', ['service_id' => $this->service->id])->assertUnauthorized();
        $this->patchJson("/api/queue/{$entry->id}/cancel")->assertUnauthorized();
        $this->getJson('/api/staff/queue')->assertUnauthorized();
        $this->postJson('/api/staff/queue/call-next', ['service_id' => $this->service->id])->assertUnauthorized();
        foreach (['serve', 'skip', 'complete'] as $action) {
            $this->patchJson("/api/staff/queue/{$entry->id}/{$action}")->assertUnauthorized();
        }
    }

    public function test_empty_queue_and_inactive_services_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->postJson('/api/staff/queue/call-next', ['service_id' => $this->service->id])
            ->assertNotFound();
        $this->service->update(['is_active' => false]);
        $this->postJson('/api/staff/queue/call-next', ['service_id' => $this->service->id])
            ->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->postJson('/api/queue/join', ['service_id' => $this->service->id])
            ->assertUnprocessable();
    }

    #[DataProvider('activeStatuses')]
    public function test_an_active_customer_cannot_join_twice(string $status): void
    {
        $entry = $this->createEntry($status);

        $this->actingAs($entry->user)->postJson('/api/queue/join', [
            'service_id' => $this->service->id,
        ])->assertConflict();
        $this->assertSame(1, $entry->user->queueEntries()->count());
    }

    public static function activeStatuses(): array
    {
        return [['waiting'], ['called'], ['serving']];
    }

    private function createEntry(string $status = 'waiting', int $number = 1): QueueEntry
    {
        return QueueEntry::create([
            'user_id' => User::factory()->create(['role' => 'customer'])->id,
            'service_id' => $this->service->id,
            'queue_number' => $number,
            'queue_date' => now()->toDateString(),
            'status' => $status,
            'joined_at' => now(),
        ]);
    }
}
