<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name'          => 'Monthly',
                'slug'          => 'monthly',
                'price'         => 50000.00,
                'currency'      => 'IDR',
                'duration_days' => 30,
                'features'      => json_encode([
                    'unlimited_access'   => true,
                    'priority_support'   => false,
                    'export_data'        => true,
                    'max_devices'        => 2,
                ]),
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'name'          => 'Yearly',
                'slug'          => 'yearly',
                'price'         => 500000.00,
                'currency'      => 'IDR',
                'duration_days' => 365,
                'features'      => json_encode([
                    'unlimited_access'   => true,
                    'priority_support'   => true,
                    'export_data'        => true,
                    'max_devices'        => 5,
                ]),
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
        ];

        // Upsert agar idempotent (aman dijalankan ulang)
        DB::table('subscription_plans')->upsert(
            $plans,
            uniqueBy: ['slug'],
            update: ['name', 'price', 'currency', 'duration_days', 'features', 'is_active', 'updated_at'],
        );
    }
}
