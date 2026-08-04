<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StaffUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            [
                'email' => 'staff@queuewise.test',
            ],
            [
                'name' => 'QueueWise Staff',
                'password' => Hash::make('password123'),
                'role' => 'staff',
            ],
        );
    }
}