<?php

namespace Tests\Feature;

use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_create_list_and_edit_services(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $id = $this->postJson('/api/admin/services', $this->serviceData())
            ->assertCreated()->assertJsonPath('service.name', 'Test Service')
            ->assertJsonPath('service.is_active', true)->json('service.id');

        $this->getJson('/api/admin/services')->assertOk()
            ->assertJsonCount(1, 'services')->assertJsonPath('services.0.id', $id);
        $this->patchJson("/api/admin/services/{$id}", [
            'name' => 'Updated Service',
            'description' => null,
            'average_service_minutes' => 25,
        ])->assertOk()->assertJsonPath('service.name', 'Updated Service')
            ->assertJsonPath('service.description', null)
            ->assertJsonPath('service.average_service_minutes', 25)
            ->assertJsonPath('service.is_active', true);
        $this->assertSame('Updated Service', Service::findOrFail($id)->name);
    }

    public function test_inactive_services_are_visible_to_admins_but_not_joinable_by_customers(): void
    {
        $service = Service::create($this->serviceData());
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($admin)->patchJson("/api/admin/services/{$service->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('service.is_active', false);
        $this->getJson('/api/admin/services')->assertJsonCount(1, 'services');
        $this->actingAs($customer)->getJson('/api/services')->assertOk()->assertJsonCount(0, 'services');
        $this->postJson('/api/queue/join', ['service_id' => $service->id])->assertUnprocessable();

        $this->actingAs($admin)->patchJson("/api/admin/services/{$service->id}", ['is_active' => true])
            ->assertOk()->assertJsonPath('service.is_active', true);
        $this->actingAs($customer)->getJson('/api/services')->assertJsonCount(1, 'services');
        $this->postJson('/api/queue/join', ['service_id' => $service->id])->assertCreated();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_administrators_cannot_manage_services(string $role): void
    {
        $service = Service::create($this->serviceData());
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->getJson('/api/admin/services')->assertForbidden();
        $this->postJson('/api/admin/services', $this->serviceData())->assertForbidden();
        $this->patchJson("/api/admin/services/{$service->id}", ['is_active' => false])->assertForbidden();
        $this->assertTrue($service->fresh()->is_active);
        $this->assertSame(1, Service::count());
    }

    public static function nonAdminRoles(): array
    {
        return [['customer'], ['staff']];
    }

    public function test_service_management_requires_authentication(): void
    {
        $service = Service::create($this->serviceData());
        $this->getJson('/api/admin/services')->assertUnauthorized();
        $this->postJson('/api/admin/services', $this->serviceData())->assertUnauthorized();
        $this->patchJson("/api/admin/services/{$service->id}", ['is_active' => false])->assertUnauthorized();
    }

    #[DataProvider('activeQueueStatuses')]
    public function test_deactivation_does_not_strand_active_queues(string $status): void
    {
        $service = Service::create($this->serviceData());
        $entry = $this->queueEntry($service, $status);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson("/api/admin/services/{$service->id}", [
                'is_active' => false,
                'name' => 'Should not be saved',
            ])->assertConflict();
        $this->assertTrue($service->fresh()->is_active);
        $this->assertSame('Test Service', $service->fresh()->name);
        $this->assertSame($status, $entry->fresh()->status);

        $this->patchJson("/api/admin/services/{$service->id}", ['average_service_minutes' => 30])
            ->assertOk();
        $this->assertSame(30, $service->fresh()->average_service_minutes);
    }

    public static function activeQueueStatuses(): array
    {
        return [['waiting'], ['called'], ['serving']];
    }

    #[DataProvider('finishedQueueStatuses')]
    public function test_deactivation_preserves_finished_queue_history(string $status): void
    {
        $service = Service::create($this->serviceData());
        $entry = $this->queueEntry($service, $status);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson("/api/admin/services/{$service->id}", ['is_active' => false])->assertOk();
        $this->assertModelExists($entry);
        $this->assertSame($status, $entry->fresh()->status);
        $this->assertFalse($service->fresh()->is_active);
    }

    public static function finishedQueueStatuses(): array
    {
        return [['completed'], ['cancelled'], ['skipped']];
    }

    public function test_yesterdays_queue_does_not_block_deactivation(): void
    {
        $service = Service::create($this->serviceData());
        $entry = $this->queueEntry($service, 'waiting');
        $entry->update(['queue_date' => now()->subDay()->toDateString()]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson("/api/admin/services/{$service->id}", ['is_active' => false])->assertOk();
        $this->assertModelExists($entry);
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_service_data_is_rejected_on_creation_and_update(string $field, mixed $value): void
    {
        $service = Service::create($this->serviceData());
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $data = array_replace($this->serviceData(), ['name' => 'Another service'], [$field => $value]);
        $this->postJson('/api/admin/services', $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson("/api/admin/services/{$service->id}", [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(1, Service::count());
        $this->assertSame('Test Service', $service->fresh()->name);
    }

    public static function invalidFields(): array
    {
        return [
            ['name', '   '],
            ['name', str_repeat('x', 256)],
            ['description', str_repeat('x', 2001)],
            ['average_service_minutes', 0],
            ['average_service_minutes', -1],
            ['average_service_minutes', 1441],
            ['average_service_minutes', 1.5],
            ['average_service_minutes', null],
            ['is_active', 'invalid'],
            ['is_active', null],
        ];
    }

    public function test_names_are_unique_but_can_be_retained_during_editing(): void
    {
        $service = Service::create($this->serviceData());
        $other = Service::create(array_replace($this->serviceData(), ['name' => 'Other service']));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/services', $this->serviceData())
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson("/api/admin/services/{$other->id}", ['name' => $service->name])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson("/api/admin/services/{$service->id}", ['name' => $service->name])->assertOk();
    }

    public function test_creation_requires_fields_and_unknown_services_return_not_found(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/services', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'average_service_minutes', 'is_active']);
        $this->patchJson('/api/admin/services/99999', ['is_active' => false])->assertNotFound();
    }

    public function test_public_registration_cannot_create_an_administrator(): void
    {
        $this->postJson('/register', [
            'name' => 'Customer',
            'email' => 'customer@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ])->assertCreated()->assertJsonPath('user.role', 'customer');
        $this->getJson('/api/admin/services')->assertForbidden();
    }

    public function test_development_admin_seeder_creates_an_account_without_resetting_existing_accounts(): void
    {
        $this->seed(AdminUserSeeder::class);
        $admin = User::where('email', 'admin@queuewise.test')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('password123', $admin->password));
        $admin->update(['password' => 'changed-password']);
        $this->seed(AdminUserSeeder::class);
        $this->assertTrue(Hash::check('changed-password', $admin->fresh()->password));
        $this->assertSame(1, User::where('email', 'admin@queuewise.test')->count());
    }

    private function serviceData(): array
    {
        return [
            'name' => 'Test Service',
            'description' => 'A test service',
            'average_service_minutes' => 15,
            'is_active' => true,
        ];
    }

    private function queueEntry(Service $service, string $status): QueueEntry
    {
        return QueueEntry::create([
            'user_id' => User::factory()->create(['role' => 'customer'])->id,
            'service_id' => $service->id,
            'queue_number' => 1,
            'queue_date' => now()->toDateString(),
            'joined_at' => now(),
            'status' => $status,
        ]);
    }
}
