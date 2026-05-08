<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SubscriptionPlanSeeder::class,  // must run first — users need plan IDs
            AdminUserSeeder::class,
            TestUserSeeder::class,
            ReferralSettingSeeder::class,   // default referral configuration
        ]);
    }
}
