<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('The test administrator can only be seeded locally or in tests.');
        }

        User::firstOrCreate(
            ['email' => 'admin@queuewise.test'],
            [
                'name' => 'QueueWise Administrator',
                'password' => Hash::make('password123'),
                'role' => 'admin',
            ],
        );
    }
}
