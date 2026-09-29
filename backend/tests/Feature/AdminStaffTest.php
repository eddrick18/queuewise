<?php

namespace Tests\Feature;

use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminStaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_create_staff_with_a_hashed_password_and_edit_details(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->postJson('/api/admin/staff', $this->staffData())
            ->assertCreated()->assertJsonPath('staff.role', 'staff')
            ->assertJsonPath('staff.is_active', true)
            ->assertJsonPath('staff.email', 'newstaff@example.test')
            ->assertJsonMissingPath('staff.password')
            ->assertJsonMissingPath('staff.remember_token');
        $staff = User::findOrFail($response->json('staff.id'));
        $this->assertTrue(Hash::check('test-password123', $staff->password));
        $this->patchJson("/api/admin/staff/{$staff->id}", [
            'name' => 'Updated Staff',
            'email' => ' UPDATED@example.test ',
        ])->assertOk()->assertJsonPath('staff.name', 'Updated Staff')
            ->assertJsonPath('staff.email', 'updated@example.test');
        $this->assertTrue(Hash::check('test-password123', $staff->fresh()->password));
    }

    public function test_staff_list_excludes_customer_and_admin_accounts_and_secrets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'customer']);
        User::factory()->create(['role' => 'staff', 'name' => 'Active Staff']);
        User::factory()->create(['role' => 'staff', 'name' => 'Inactive Staff', 'is_active' => false]);
        $this->actingAs($admin)->getJson('/api/admin/staff')->assertOk()
            ->assertJsonCount(2, 'staff')->assertJsonPath('staff.0.name', 'Active Staff')
            ->assertJsonPath('staff.1.is_active', false)
            ->assertJsonMissingPath('staff.0.password')->assertJsonMissingPath('staff.0.remember_token');
    }

    #[DataProvider('nonAdminRoles')]
    public function test_only_administrators_can_manage_staff(string $role): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->getJson('/api/admin/staff')->assertForbidden();
        $this->postJson('/api/admin/staff', $this->staffData())->assertForbidden();
        $this->patchJson("/api/admin/staff/{$staff->id}", ['is_active' => false])->assertForbidden();
        $this->assertTrue($staff->fresh()->is_active);
    }

    public static function nonAdminRoles(): array
    {
        return [['staff'], ['customer']];
    }

    public function test_staff_management_requires_authentication(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->getJson('/api/admin/staff')->assertUnauthorized();
        $this->postJson('/api/admin/staff', $this->staffData())->assertUnauthorized();
        $this->patchJson("/api/admin/staff/{$staff->id}", ['is_active' => false])->assertUnauthorized();
    }

    public function test_administrator_can_deactivate_and_reactivate_staff(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($admin)->patchJson("/api/admin/staff/{$staff->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('staff.is_active', false);
        $this->assertFalse($staff->fresh()->is_active);
        $this->assertModelExists($staff);
        $this->patchJson("/api/admin/staff/{$staff->id}", ['is_active' => true])
            ->assertOk()->assertJsonPath('staff.is_active', true);
        $this->assertTrue($staff->fresh()->is_active);
    }

    public function test_deactivated_staff_cannot_log_in_even_with_the_correct_password(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'is_active' => false]);
        $this->postJson('/login', ['email' => $staff->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'Your account has been deactivated. Please contact an administrator.');
        $this->assertGuest();
        $staff->update(['is_active' => true]);
        $this->postJson('/login', ['email' => $staff->email, 'password' => 'password'])->assertOk();
    }

    public function test_deactivation_blocks_an_already_authenticated_session_but_allows_logout(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->postJson('/login', ['email' => $staff->email, 'password' => 'password'])->assertOk();
        $this->getJson('/api/staff/queue')->assertOk();

        $this->app['auth']->guard('web')->user()->update(['is_active' => false]);
        $service = Service::create(['name' => 'Session test', 'average_service_minutes' => 10, 'is_active' => true]);
        $entry = QueueEntry::create([
            'user_id' => User::factory()->create()->id, 'service_id' => $service->id,
            'queue_number' => 1, 'queue_date' => now()->toDateString(),
            'joined_at' => now(), 'status' => 'called',
        ]);
        $this->getJson('/api/user')->assertForbidden();
        $this->getJson('/api/services')->assertForbidden();
        $this->getJson('/api/staff/queue')->assertForbidden();
        $this->postJson('/api/staff/queue/call-next', ['service_id' => 1])->assertForbidden();
        foreach (['serve', 'skip', 'complete'] as $action) {
            $this->patchJson("/api/staff/queue/{$entry->id}/{$action}")->assertForbidden();
        }
        $this->assertSame('called', $entry->fresh()->status);
        $this->postJson('/logout')->assertOk();
        $this->assertGuest('web');
    }

    public function test_customer_and_admin_accounts_cannot_be_changed_through_staff_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($admin);
        foreach ([$admin, $customer] as $account) {
            $this->patchJson("/api/admin/staff/{$account->id}", ['is_active' => false])->assertNotFound();
            $this->assertTrue($account->fresh()->is_active);
        }
        $this->patchJson('/api/admin/staff/999999', ['is_active' => false])->assertNotFound();
    }

    public function test_staff_routes_cannot_promote_accounts_or_change_existing_passwords(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/staff', [...$this->staffData(), 'role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->patchJson("/api/admin/staff/{$staff->id}", ['role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->patchJson("/api/admin/staff/{$staff->id}", ['password' => 'changed-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame('staff', $staff->fresh()->role);
        $this->assertTrue(Hash::check('password', $staff->fresh()->password));
    }

    public function test_emails_are_unique_across_all_roles_and_normalized_before_validation(): void
    {
        User::factory()->create(['email' => 'existing@example.test', 'role' => 'customer']);
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/staff', [...$this->staffData(), 'email' => 'EXISTING@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson("/api/admin/staff/{$staff->id}", ['email' => 'EXISTING@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson("/api/admin/staff/{$staff->id}", ['email' => $staff->email])->assertOk();
    }

    #[DataProvider('invalidFields')]
    public function test_staff_creation_validates_input(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/staff', [...$this->staffData(), $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field === 'password_confirmation' ? 'password' : $field);
        $this->assertSame(0, User::where('role', 'staff')->count());
    }

    public static function invalidFields(): array
    {
        return [
            ['name', '   '],
            ['name', str_repeat('x', 256)],
            ['email', 'not-an-email'],
            ['email', null],
            ['password', 'short'],
            ['password', null],
            ['password', str_repeat('x', 73)],
            ['password_confirmation', 'different'],
            ['is_active', 'invalid'],
            ['is_active', null],
        ];
    }

    public function test_update_validates_input_without_changing_staff(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'name' => 'Original']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson("/api/admin/staff/{$staff->id}", ['name' => '', 'email' => 'invalid', 'is_active' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'is_active']);
        $this->assertSame('Original', $staff->fresh()->name);
        $this->assertTrue($staff->fresh()->is_active);
    }

    public function test_new_public_accounts_are_active_customers_regardless_of_submitted_role_and_status(): void
    {
        $this->postJson('/register', [
            'name' => 'Customer', 'email' => 'customer@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => 'admin', 'is_active' => false,
        ])->assertCreated()->assertJsonPath('user.role', 'customer')->assertJsonPath('user.is_active', true);
        $this->getJson('/api/services')->assertOk();
    }

    private function staffData(): array
    {
        return [
            'name' => 'New Staff', 'email' => ' NewStaff@example.test ',
            'password' => 'test-password123', 'password_confirmation' => 'test-password123',
        ];
    }
}
