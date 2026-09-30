<?php

namespace Tests\Feature;

use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminQueueHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_history(): void
    {
        $this->getJson('/api/admin/queue-history')->assertUnauthorized();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_other_roles_cannot_read_history(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->getJson('/api/admin/queue-history')->assertForbidden();
    }

    public static function nonAdminRoles(): array
    {
        return [['customer'], ['staff']];
    }

    public function test_inactive_admin_cannot_read_history(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => false]))
            ->getJson('/api/admin/queue-history')->assertForbidden();
    }

    public function test_default_date_summary_counts_all_statuses_but_table_only_contains_finished_visits(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));
        $service = $this->service();
        foreach (['waiting', 'called', 'serving', 'completed', 'cancelled', 'skipped'] as $status) {
            $this->entry($service, $status, '2026-09-30');
        }
        $this->entry($service, 'completed', '2026-09-29');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/queue-history')->assertOk()
            ->assertJsonPath('date', '2026-09-30')
            ->assertJsonPath('summary.total', 6)->assertJsonPath('summary.active', 3)
            ->assertJsonPath('summary.completed', 1)->assertJsonPath('summary.cancelled', 1)
            ->assertJsonPath('summary.skipped', 1)->assertJsonPath('entries.total', 3)
            ->assertJsonPath('entries.data.0.status', 'skipped')
            ->assertJsonPath('entries.data.1.status', 'cancelled')
            ->assertJsonPath('entries.data.2.status', 'completed');
    }

    public function test_date_and_inactive_service_filter_apply_to_both_summary_and_history(): void
    {
        $service = $this->service(false);
        $entry = $this->entry($service, 'completed', '2026-08-01');
        $this->entry($service, 'cancelled', '2026-08-02');
        $this->entry($this->service(), 'skipped', '2026-08-01');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/queue-history?date=2026-08-01&service_id='.$service->id)
            ->assertOk()->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.completed', 1)->assertJsonPath('entries.total', 1)
            ->assertJsonPath('entries.data.0.id', $entry->id)
            ->assertJsonPath('entries.data.0.service.is_active', false);
    }

    public function test_status_filter_does_not_reduce_daily_totals(): void
    {
        $service = $this->service();
        $this->entry($service, 'completed');
        $cancelled = $this->entry($service, 'cancelled');
        $this->entry($service, 'skipped');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/queue-history?status=cancelled')->assertOk()
            ->assertJsonPath('summary.total', 3)->assertJsonPath('summary.completed', 1)
            ->assertJsonPath('summary.skipped', 1)->assertJsonPath('entries.total', 1)
            ->assertJsonPath('entries.data.0.id', $cancelled->id);
    }

    public function test_pagination_is_stable_for_equal_join_times_and_totals_cover_all_pages(): void
    {
        $service = $this->service();
        $ids = [];
        for ($index = 0; $index < 22; $index++) {
            $ids[] = $this->entry($service, 'completed')->id;
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $first = $this->getJson('/api/admin/queue-history')->assertOk()
            ->assertJsonCount(20, 'entries.data')->assertJsonPath('entries.last_page', 2)
            ->assertJsonPath('summary.total', 22)->json('entries.data');
        $second = $this->getJson('/api/admin/queue-history?page=2')->assertOk()
            ->assertJsonCount(2, 'entries.data')->assertJsonPath('entries.current_page', 2)
            ->assertJsonPath('summary.total', 22)->json('entries.data');
        $this->assertSame(array_reverse($ids), array_column([...$first, ...$second], 'id'));
    }

    public function test_timestamps_and_customer_name_are_returned_without_account_secrets(): void
    {
        $entry = $this->entry($this->service(), 'completed');
        $entry->update(['called_at' => now()->startOfDay()->addHour(), 'completed_at' => now()->startOfDay()->addHours(2)]);
        $data = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/queue-history')->assertOk()->json('entries.data.0');
        $this->assertSame($entry->user->name, $data['customer_name']);
        $this->assertSame($entry->called_at->toIso8601String(), $data['called_at']);
        $this->assertSame($entry->completed_at->toIso8601String(), $data['completed_at']);
        $this->assertArrayNotHasKey('user', $data);
        $this->assertArrayNotHasKey('email', $data);
        $this->assertArrayNotHasKey('password', $data);
    }

    public function test_cancelled_entry_has_no_invented_call_or_completion_time(): void
    {
        $this->entry($this->service(), 'cancelled');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/queue-history')->assertOk()
            ->assertJsonCount(1, 'entries.data')
            ->assertJsonPath('entries.data.0.called_at', null)
            ->assertJsonPath('entries.data.0.completed_at', null);
    }

    public function test_empty_day_returns_zero_totals_and_an_empty_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/queue-history?date=2020-01-01')->assertOk()
            ->assertJsonPath('summary.total', 0)->assertJsonPath('summary.active', 0)
            ->assertJsonPath('summary.completed', 0)->assertJsonPath('summary.cancelled', 0)
            ->assertJsonPath('summary.skipped', 0)->assertJsonCount(0, 'entries.data');
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected(array $filters, string $field): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/queue-history?'.http_build_query($filters))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return [
            [['date' => 'not-a-date'], 'date'],
            [['date' => '2026-02-30'], 'date'],
            [['date' => ''], 'date'],
            [['service_id' => 999999], 'service_id'],
            [['service_id' => 'abc'], 'service_id'],
            [['status' => 'waiting'], 'status'],
            [['status' => 'unknown'], 'status'],
            [['page' => 0], 'page'],
            [['page' => 'abc'], 'page'],
            [['page' => 1000001], 'page'],
        ];
    }

    private function service(bool $active = true): Service
    {
        return Service::create([
            'name' => fake()->unique()->word(),
            'average_service_minutes' => 10,
            'is_active' => $active,
        ]);
    }

    private function entry(Service $service, string $status, ?string $date = null): QueueEntry
    {
        $date ??= now()->toDateString();

        return QueueEntry::create([
            'user_id' => User::factory()->create(['role' => 'customer'])->id,
            'service_id' => $service->id,
            'queue_number' => QueueEntry::where('service_id', $service->id)->whereDate('queue_date', $date)->max('queue_number') + 1,
            'queue_date' => $date,
            'status' => $status,
            'joined_at' => $date.' 00:00:00',
        ]);
    }
}
