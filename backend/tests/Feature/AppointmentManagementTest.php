<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppointmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 01:00:00', 'UTC'));
    }

    public function test_rescheduling_moves_the_reservation_and_releases_the_old_slot(): void
    {
        $appointment = $this->appointment();
        $this->actingAs($appointment->user)->patchJson('/api/appointments/'.$appointment->id.'/reschedule', $this->newSlot())->assertOk();
        $appointment->refresh();
        $this->assertSame('2026-10-01 03:00:00', $appointment->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertTrue($appointment->scheduled_at->equalTo($appointment->reserved_slot));
        $this->assertSame(1, Appointment::count());
        $this->assertSame(0, QueueEntry::count());
        $this->actingAs(User::factory()->create(['role' => 'customer']))->postJson('/api/appointments', [
            'service_id' => $appointment->service_id, 'date' => '2026-09-30', 'time' => '10:00',
        ])->assertCreated();
    }

    public function test_occupied_new_slot_preserves_original_booking(): void
    {
        $appointment = $this->appointment();
        Appointment::factory()->create(['service_id' => $appointment->service_id, 'scheduled_at' => '2026-10-01 03:00:00', 'reserved_slot' => '2026-10-01 03:00:00']);
        $this->actingAs($appointment->user)->patchJson('/api/appointments/'.$appointment->id.'/reschedule', $this->newSlot())->assertConflict();
        $this->assertSame('2026-09-30 02:00:00', $appointment->fresh()->reserved_slot->format('Y-m-d H:i:s'));
        $this->assertSame('booked', $appointment->fresh()->status);
    }

    public function test_customer_overlap_across_services_is_rejected(): void
    {
        $appointment = $this->appointment();
        Appointment::factory()->create(['user_id' => $appointment->user_id, 'scheduled_at' => '2026-10-01 03:00:00', 'reserved_slot' => '2026-10-01 03:00:00']);
        $this->actingAs($appointment->user)->patchJson('/api/appointments/'.$appointment->id.'/reschedule', $this->newSlot())->assertConflict();
        $this->assertSame('2026-09-30 02:00:00', $appointment->fresh()->scheduled_at->format('Y-m-d H:i:s'));
    }

    #[DataProvider('closedStatuses')]
    public function test_closed_appointments_cannot_be_rescheduled_or_marked_no_show(string $status): void
    {
        $appointment = $this->appointment();
        $appointment->update(['status' => $status]);
        $this->actingAs($appointment->user)->patchJson('/api/appointments/'.$appointment->id.'/reschedule', $this->newSlot())->assertConflict();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 03:00:00', 'UTC'));
        $this->actingAs(User::factory()->create(['role' => 'staff']))->patchJson('/api/staff/appointments/'.$appointment->id.'/no-show')->assertConflict();
        $this->assertSame($status, $appointment->fresh()->status);
    }

    public static function closedStatuses(): array
    {
        return [['checked_in'], ['cancelled'], ['no_show']];
    }

    public function test_past_appointments_and_inactive_services_cannot_be_rescheduled(): void
    {
        $appointment = $this->appointment();
        $appointment->service->update(['is_active' => false]);
        $this->actingAs($appointment->user)->patchJson('/api/appointments/'.$appointment->id.'/reschedule', $this->newSlot())->assertConflict();
        $appointment->service->update(['is_active' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-09-30 02:00:00', 'UTC'));
        $this->patchJson('/api/appointments/'.$appointment->id.'/reschedule', $this->newSlot())->assertConflict();
    }

    #[DataProvider('invalidSlots')]
    public function test_invalid_new_times_preserve_the_original(array $slot): void
    {
        $appointment = $this->appointment();
        $this->actingAs($appointment->user)->patchJson('/api/appointments/'.$appointment->id.'/reschedule', $slot)->assertUnprocessable();
        $this->assertSame('2026-09-30 02:00:00', $appointment->fresh()->reserved_slot->format('Y-m-d H:i:s'));
    }

    public static function invalidSlots(): array
    {
        return [
            [['date' => '2026-10-03', 'time' => '11:00']],
            [['date' => '2026-10-01', 'time' => '17:00']],
            [['date' => '2026-10-01', 'time' => '11:15']],
            [['date' => '2026-09-30', 'time' => '08:30']],
            [['date' => '2026-11-02', 'time' => '11:00']],
        ];
    }

    public function test_no_show_is_allowed_only_after_the_slot_ends(): void
    {
        $appointment = $this->appointment();
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $this->patchJson('/api/staff/appointments/'.$appointment->id.'/no-show')->assertConflict();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 02:29:59', 'UTC'));
        $this->patchJson('/api/staff/appointments/'.$appointment->id.'/no-show')->assertConflict();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 02:30:00', 'UTC'));
        $this->patchJson('/api/staff/appointments/'.$appointment->id.'/no-show')->assertOk();
        $this->assertSame('no_show', $appointment->fresh()->status);
        $this->assertNull($appointment->fresh()->reserved_slot);
        $this->patchJson('/api/staff/appointments/'.$appointment->id.'/check-in')->assertConflict();
        $this->assertSame(0, QueueEntry::count());
    }

    public function test_access_controls_protect_new_actions_and_history(): void
    {
        $appointment = $this->appointment();
        $this->getJson('/api/appointments/history')->assertUnauthorized();
        $this->patchJson('/api/appointments/'.$appointment->id.'/reschedule', $this->newSlot())->assertUnauthorized();
        $this->patchJson('/api/staff/appointments/'.$appointment->id.'/no-show')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $this->patchJson('/api/appointments/'.$appointment->id.'/reschedule', $this->newSlot())->assertForbidden();
        $this->patchJson('/api/staff/appointments/'.$appointment->id.'/no-show')->assertForbidden();
        $this->getJson('/api/staff/appointments/history')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $this->patchJson('/api/appointments/'.$appointment->id.'/reschedule', $this->newSlot())->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => false]));
        $this->getJson('/api/staff/appointments/history')->assertForbidden();
    }

    public function test_history_includes_outcomes_and_overdue_bookings_but_excludes_future_bookings(): void
    {
        $appointment = $this->appointment();
        $customer = $appointment->user;
        $this->actingAs($customer)->getJson('/api/appointments/history')->assertOk()->assertJsonPath('appointments.total', 0);
        foreach (['cancelled', 'no_show'] as $status) {
            Appointment::factory()->create(['user_id' => $customer->id, 'status' => $status, 'reserved_slot' => null]);
        }
        $completed = Appointment::factory()->create(['user_id' => $customer->id, 'status' => 'checked_in']);
        $queue = QueueEntry::create(['user_id' => $customer->id, 'service_id' => $completed->service_id, 'queue_number' => 1, 'queue_date' => '2026-09-30', 'joined_at' => now(), 'status' => 'completed']);
        $completed->update(['queue_entry_id' => $queue->id]);
        Appointment::factory()->create(['status' => 'cancelled', 'reserved_slot' => null]);
        $this->travelTo(CarbonImmutable::parse('2026-09-30 02:30:00', 'UTC'));
        $items = $this->getJson('/api/appointments/history')->assertOk()->assertJsonPath('appointments.total', 4)->json('appointments.data');
        $this->assertContains('completed', array_column(array_filter(array_column($items, 'queue_entry')), 'status'));
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/staff/appointments/history')->assertOk()->assertJsonPath('appointments.total', 5);
    }

    public function test_history_pagination_has_no_duplicates_and_validates_page(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        Appointment::factory()->count(22)->create(['user_id' => $customer->id, 'status' => 'cancelled', 'reserved_slot' => null]);
        $this->actingAs($customer);
        $first = $this->getJson('/api/appointments/history')->assertOk()->assertJsonCount(20, 'appointments.data')->json('appointments.data');
        $second = $this->getJson('/api/appointments/history?page=2')->assertOk()->assertJsonCount(2, 'appointments.data')->json('appointments.data');
        $this->assertCount(22, array_unique(array_column([...$first, ...$second], 'id')));
        $this->getJson('/api/appointments/history?page=0')->assertUnprocessable();
    }

    private function appointment(): Appointment
    {
        return Appointment::factory()->create(['scheduled_at' => '2026-09-30 02:00:00', 'reserved_slot' => '2026-09-30 02:00:00']);
    }

    private function newSlot(): array
    {
        return ['date' => '2026-10-01', 'time' => '11:00'];
    }
}
