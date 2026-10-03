<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        $time = now('Asia/Manila')->nextWeekday()->setTime(10, 0)->utc();

        return [
            'user_id' => User::factory()->state(['role' => 'customer']),
            'service_id' => fn () => Service::create(['name' => fake()->unique()->word(), 'average_service_minutes' => 15, 'is_active' => true])->id,
            'scheduled_at' => $time,
            'reserved_slot' => $time,
            'status' => 'booked',
        ];
    }
}
