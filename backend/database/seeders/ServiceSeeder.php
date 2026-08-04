<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            [
                'name' => 'General Inquiry',
                'description' =>
                    'General questions and customer assistance.',
                'average_service_minutes' => 10,
                'is_active' => true,
            ],
            [
                'name' => 'Document Processing',
                'description' =>
                    'Submission and processing of documents.',
                'average_service_minutes' => 20,
                'is_active' => true,
            ],
            [
                'name' => 'Payment Assistance',
                'description' =>
                    'Payment verification and billing assistance.',
                'average_service_minutes' => 15,
                'is_active' => true,
            ],
            [
                'name' => 'Technical Support',
                'description' =>
                    'Assistance with technical problems.',
                'average_service_minutes' => 25,
                'is_active' => true,
            ],
        ];

        foreach ($services as $service) {
            Service::updateOrCreate(
                ['name' => $service['name']],
                $service,
            );
        }
    }
}