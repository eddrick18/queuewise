<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $staff;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 01:00:00', 'UTC'));
        $this->customer = User::factory()->create(['role' => 'customer']);
        $this->staff = User::factory()->create(['role' => 'staff']);
        $this->service = Service::create(['name' => 'Appointments', 'average_service_minutes' => 15, 'is_active' => true]);
    }

    public function test_booking_uses_philippine_time_and_reserves_the_slot(): void
    {
        $this->actingAs($this->customer)->postJson('/api/appointments', $this->booking())->assertCreated();
        $appointment = Appointment::firstOrFail();
        $this->assertSame('2026-09-30 02:00:00', $appointment->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->customer->id, $appointment->user_id);
        $this->assertSame(0, QueueEntry::count());
        $slots = $this->getJson('/api/appointments/slots?date=2026-09-30&service_id='.$this->service->id)->assertOk()->json('slots');
        $this->assertNotContains('10:00', $slots);
        $this->assertNotContains('08:00', $slots);
        $this->assertContains('16:30', $slots);
        $this->assertNotContains('17:00', $slots);
    }

    public function test_slot_cannot_be_double_booked_and_cancellation_releases_it(): void
    {
        $id = $this->actingAs($this->customer)->postJson('/api/appointments', $this->booking())->assertCreated()->json('appointment.id');
        $other = User::factory()->create(['role' => 'customer']);
        $this->actingAs($other)->postJson('/api/appointments', $this->booking())->assertConflict();
        $this->patchJson('/api/appointments/'.$id.'/cancel')->assertForbidden();
        $this->actingAs($this->customer)->patchJson('/api/appointments/'.$id.'/cancel')->assertOk();
        $this->actingAs($other)->postJson('/api/appointments', $this->booking())->assertCreated();
        $this->assertSame(2, Appointment::count());
    }

    public function test_customer_cannot_book_two_services_at_the_same_time(): void
    {
        $other = Service::create(['name' => 'Other', 'average_service_minutes' => 15, 'is_active' => true]);
        $this->actingAs($this->customer)->postJson('/api/appointments', $this->booking())->assertCreated();
        $this->postJson('/api/appointments', [...$this->booking(), 'service_id' => $other->id])->assertConflict();
    }

    #[DataProvider('invalidBookings')]
    public function test_invalid_booking_times_are_rejected(string $date, string $time): void
    {
        $this->actingAs($this->customer)->postJson('/api/appointments', [...$this->booking(), 'date' => $date, 'time' => $time])->assertUnprocessable();
        $this->assertSame(0, Appointment::count());
    }

    public static function invalidBookings(): array
    {
        return [
            ['2026-09-29', '10:00'], ['2026-10-03', '10:00'], ['2026-11-02', '10:00'],
            ['2026-09-30', '08:00'], ['2026-09-30', '09:00'], ['2026-10-01', '07:30'],
            ['2026-10-01', '17:00'], ['2026-10-01', '10:15'], ['2026-02-30', '10:00'],
        ];
    }

    public function test_customer_only_sees_their_appointments_and_staff_can_see_everyone(): void
    {
        $mine = $this->appointment();
        Appointment::factory()->create(['scheduled_at' => $mine->scheduled_at, 'reserved_slot' => $mine->scheduled_at]);
        $this->actingAs($this->customer)->getJson('/api/appointments?date=2026-09-30')->assertOk()->assertJsonCount(1, 'appointments');
        $this->actingAs($this->staff)->getJson('/api/staff/appointments?date=2026-09-30')->assertOk()->assertJsonCount(2, 'appointments');
        $this->getJson('/api/staff/appointments?date=2026-10-01')->assertOk()->assertJsonCount(0, 'appointments');
    }

    public function test_routes_enforce_roles_and_active_accounts(): void
    {
        $appointment = $this->appointment();
        $this->getJson('/api/appointments')->assertUnauthorized();
        $this->actingAs($this->customer)->patchJson('/api/staff/appointments/'.$appointment->id.'/check-in')->assertForbidden();
        $this->getJson('/api/staff/appointments')->assertForbidden();
        $this->actingAs($this->staff)->postJson('/api/appointments', $this->booking())->assertForbidden();
        $this->customer->update(['is_active' => false]);
        $this->actingAs($this->customer)->postJson('/api/appointments', $this->booking())->assertForbidden();
    }

    public function test_check_in_creates_one_queue_entry_and_cannot_be_repeated_or_cancelled_as_booking(): void
    {
        $appointment = $this->appointment();
        $this->actingAs($this->staff)->patchJson('/api/staff/appointments/'.$appointment->id.'/check-in')->assertCreated();
        $this->patchJson('/api/staff/appointments/'.$appointment->id.'/check-in')->assertConflict();
        $this->assertSame(1, QueueEntry::count());
        $this->assertSame('checked_in', $appointment->fresh()->status);
        $this->actingAs($this->customer)->patchJson('/api/appointments/'.$appointment->id.'/cancel')->assertConflict();
        $this->postJson('/api/queue/join', ['service_id' => $this->service->id])->assertConflict();
        $this->getJson('/api/queue/current')->assertOk()->assertJsonPath('queue_entry.estimated_wait_minutes', null);
    }

    public function test_check_in_rejects_an_existing_active_queue_and_inactive_service(): void
    {
        $appointment = $this->appointment();
        $this->actingAs($this->customer)->postJson('/api/queue/join', ['service_id' => $this->service->id])->assertCreated();
        $this->actingAs($this->staff)->patchJson('/api/staff/appointments/'.$appointment->id.'/check-in')->assertConflict();
        QueueEntry::query()->update(['status' => 'completed']);
        $this->service->update(['is_active' => false]);
        $this->patchJson('/api/staff/appointments/'.$appointment->id.'/check-in')->assertConflict();
    }

    public function test_check_in_rejects_other_days_and_cancelled_appointments(): void
    {
        $appointment = $this->appointment();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 01:00:00', 'UTC'));
        $this->actingAs($this->staff)->patchJson('/api/staff/appointments/'.$appointment->id.'/check-in')->assertUnprocessable();
        $this->travelBack();
        $appointment->update(['status' => 'cancelled', 'reserved_slot' => null]);
        $this->patchJson('/api/staff/appointments/'.$appointment->id.'/check-in')->assertConflict();
    }

    public function test_priority_begins_at_booked_time_and_never_interrupts_called_customer(): void
    {
        $walkIn = User::factory()->create(['role' => 'customer']);
        $walkId = $this->actingAs($walkIn)->postJson('/api/queue/join', ['service_id' => $this->service->id])->assertCreated()->json('queue_entry.id');
        $appointment = $this->appointment();
        $appointmentQueue = $this->actingAs($this->staff)->patchJson('/api/staff/appointments/'.$appointment->id.'/check-in')->assertCreated()->json('queue_entry.id');
        $this->postJson('/api/staff/queue/call-next', ['service_id' => $this->service->id])->assertOk()->assertJsonPath('queue_entry.id', $walkId);
        $this->travelTo(CarbonImmutable::parse('2026-09-30 02:00:00', 'UTC'));
        $this->postJson('/api/staff/queue/call-next', ['service_id' => $this->service->id])->assertConflict();
        $this->patchJson('/api/staff/queue/'.$walkId.'/serve')->assertOk();
        $this->patchJson('/api/staff/queue/'.$walkId.'/complete')->assertOk();
        $this->postJson('/api/staff/queue/call-next', ['service_id' => $this->service->id])->assertOk()->assertJsonPath('queue_entry.id', $appointmentQueue);
        $this->patchJson('/api/staff/queue/'.$appointmentQueue.'/skip')->assertOk();
        $this->actingAs($this->customer)->getJson('/api/appointments')->assertOk()->assertJsonPath('appointments.0.queue_entry.status', 'skipped');
    }

    public function test_due_appointment_precedes_older_walk_in_and_position_matches(): void
    {
        $walkIn = User::factory()->create(['role' => 'customer']);
        $this->actingAs($walkIn)->postJson('/api/queue/join', ['service_id' => $this->service->id])->assertCreated();
        $appointment = $this->appointment();
        $id = $this->actingAs($this->staff)->patchJson('/api/staff/appointments/'.$appointment->id.'/check-in')->assertCreated()->json('queue_entry.id');
        $this->travelTo(CarbonImmutable::parse('2026-09-30 02:00:01', 'UTC'));
        $this->actingAs($walkIn)->getJson('/api/queue/current')->assertOk()->assertJsonPath('queue_entry.people_ahead', 1);
        $this->actingAs($this->customer)->getJson('/api/queue/current')->assertOk()->assertJsonPath('queue_entry.people_ahead', 0);
        $this->actingAs($this->staff)->postJson('/api/staff/queue/call-next', ['service_id' => $this->service->id])->assertOk()->assertJsonPath('queue_entry.id', $id);
    }

    public function test_early_appointment_alone_is_not_called(): void
    {
        $appointment = $this->appointment();
        $this->actingAs($this->staff)->patchJson('/api/staff/appointments/'.$appointment->id.'/check-in')->assertCreated();
        $this->postJson('/api/staff/queue/call-next', ['service_id' => $this->service->id])->assertNotFound();
    }

    public function test_services_with_bookings_cannot_be_deactivated_and_inactive_services_cannot_be_booked(): void
    {
        $appointment = $this->appointment();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson('/api/admin/services/'.$this->service->id, ['is_active' => false])->assertConflict();
        $appointment->update(['status' => 'cancelled', 'reserved_slot' => null]);
        $this->patchJson('/api/admin/services/'.$this->service->id, ['is_active' => false])->assertOk();
        $this->actingAs($this->customer)->postJson('/api/appointments', $this->booking())->assertUnprocessable();
    }

    private function booking(): array
    {
        return ['service_id' => $this->service->id, 'date' => '2026-09-30', 'time' => '10:00'];
    }

    private function appointment(): Appointment
    {
        return Appointment::factory()->create([
            'user_id' => $this->customer->id, 'service_id' => $this->service->id,
            'scheduled_at' => '2026-09-30 02:00:00', 'reserved_slot' => '2026-09-30 02:00:00',
        ]);
    }
}
