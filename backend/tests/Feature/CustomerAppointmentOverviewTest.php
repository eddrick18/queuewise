<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAppointmentOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_only_sees_own_today_and_future_bookings(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30 17:00:00', 'UTC'));
        $customer = User::factory()->create(['role' => 'customer']);
        $today = Appointment::factory()->create(['user_id' => $customer->id, 'scheduled_at' => '2026-10-01 00:00:00']);
        $future = Appointment::factory()->create(['user_id' => $customer->id, 'scheduled_at' => '2026-10-02 00:00:00']);
        Appointment::factory()->create(['user_id' => $customer->id, 'scheduled_at' => '2026-09-30 02:00:00']);
        Appointment::factory()->create(['user_id' => $customer->id, 'scheduled_at' => '2026-10-02 02:00:00', 'status' => 'cancelled', 'reserved_slot' => null]);
        Appointment::factory()->create(['scheduled_at' => '2026-10-01 00:00:00']);
        Appointment::factory()->create(['scheduled_at' => '2026-10-02 00:00:00']);
        $this->actingAs($customer)->getJson('/api/appointments/upcoming')->assertOk()
            ->assertJsonPath('appointments.total', 2)
            ->assertJsonPath('appointments.data.0.id', $today->id)
            ->assertJsonPath('appointments.data.1.id', $future->id);
    }

    public function test_customer_overview_requires_an_active_customer(): void
    {
        $this->getJson('/api/appointments/upcoming')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'staff']))->getJson('/api/appointments/upcoming')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'customer', 'is_active' => false]))->getJson('/api/appointments/upcoming')->assertForbidden();
    }
}
