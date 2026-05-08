<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Credentials sourced from config/subscription.php → admin.*
        // which in turn reads from the corresponding .env variables.
        User::updateOrCreate(
            ['email' => config('subscription.admin.email', 'admin@subscription.test')],
            [
                'external_user_id' => config('subscription.admin.external_user_id', 'ADMIN-001'),
                'name'             => config('subscription.admin.name', 'System Administrator'),
                // 'hashed' cast in User model bcrypts plain-text on save
                'password'         => config('subscription.admin.password', 'admin123'),
                'is_admin'         => true,
            ],
        );
    }
}
