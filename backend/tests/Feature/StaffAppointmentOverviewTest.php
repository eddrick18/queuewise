<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAppointmentOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_uses_philippine_today_and_includes_future_bookings_in_order(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30 17:00:00', 'UTC'));
        $today = Appointment::factory()->create(['scheduled_at' => '2026-10-01 00:00:00']);
        $future = Appointment::factory()->create(['scheduled_at' => '2026-10-02 00:00:00']);
        Appointment::factory()->create(['scheduled_at' => '2026-09-30 02:00:00']);
        Appointment::factory()->create(['scheduled_at' => '2026-10-02 02:00:00', 'status' => 'cancelled', 'reserved_slot' => null]);
        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->getJson('/api/staff/appointments/upcoming')->assertOk()
            ->assertJsonPath('appointments.total', 2)
            ->assertJsonPath('appointments.data.0.id', $today->id)
            ->assertJsonPath('appointments.data.1.id', $future->id);
    }

    public function test_overview_is_paginated_and_available_only_to_active_staff_and_admins(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30 01:00:00', 'UTC'));
        Appointment::factory()->count(21)->create(['scheduled_at' => '2026-10-01 00:00:00']);
        $this->getJson('/api/staff/appointments/upcoming')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'customer']))->getJson('/api/staff/appointments/upcoming')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'staff', 'is_active' => false]))->getJson('/api/staff/appointments/upcoming')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $first = $this->getJson('/api/staff/appointments/upcoming')->assertOk()->assertJsonCount(20, 'appointments.data')->json('appointments.data');
        $second = $this->getJson('/api/staff/appointments/upcoming?page=2')->assertOk()->assertJsonCount(1, 'appointments.data')->json('appointments.data');
        $this->assertCount(21, array_unique(array_column([...$first, ...$second], 'id')));
        $this->getJson('/api/staff/appointments/upcoming?page=0')->assertUnprocessable();
    }
}
