<?php

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class TestUserSeeder extends Seeder
{
    public function run(): void
    {
        $monthly = SubscriptionPlan::where('slug', 'monthly')->firstOrFail();
        $yearly  = SubscriptionPlan::where('slug', 'yearly')->firstOrFail();

        // 1 — Trial aktif (7 days remaining) ─────────────────────────────────
        $trialUser = User::updateOrCreate(
            ['email' => 'trial-active@test.local'],
            [
                'external_user_id' => 'TEST-TRIAL-ACTIVE',
                'name'             => 'Trial Active User',
                'password'         => 'password',
            ],
        );
        $this->upsertSubscription($trialUser, [
            'plan_id'          => $monthly->id,
            'status'           => SubscriptionStatus::Trial,
            'trial_start_date' => Carbon::today(),
            'trial_end_date'   => Carbon::today()->addDays(7),
            'start_date'       => Carbon::today(),
            'end_date'         => Carbon::today()->addDays(7),
        ]);

        // 2 — Trial expired ───────────────────────────────────────────────────
        $trialExpiredUser = User::updateOrCreate(
            ['email' => 'trial-expired@test.local'],
            [
                'external_user_id' => 'TEST-TRIAL-EXPIRED',
                'name'             => 'Trial Expired User',
                'password'         => 'password',
            ],
        );
        $this->upsertSubscription($trialExpiredUser, [
            'plan_id'          => $monthly->id,
            'status'           => SubscriptionStatus::Expired,
            'trial_start_date' => Carbon::today()->subDays(14),
            'trial_end_date'   => Carbon::today()->subDays(7),
            'start_date'       => Carbon::today()->subDays(14),
            'end_date'         => Carbon::today()->subDays(7),
        ]);

        // 3 — Subscription aktif (30 days remaining) ──────────────────────────
        $activeUser = User::updateOrCreate(
            ['email' => 'subscription-active@test.local'],
            [
                'external_user_id' => 'TEST-SUB-ACTIVE',
                'name'             => 'Active Subscription User',
                'password'         => 'password',
            ],
        );
        $this->upsertSubscription($activeUser, [
            'plan_id'    => $monthly->id,
            'status'     => SubscriptionStatus::Active,
            'start_date' => Carbon::today(),
            'end_date'   => Carbon::today()->addDays(30),
        ]);

        // 4 — Subscription expired ────────────────────────────────────────────
        $expiredUser = User::updateOrCreate(
            ['email' => 'subscription-expired@test.local'],
            [
                'external_user_id' => 'TEST-SUB-EXPIRED',
                'name'             => 'Expired Subscription User',
                'password'         => 'password',
            ],
        );
        $this->upsertSubscription($expiredUser, [
            'plan_id'    => $yearly->id,
            'status'     => SubscriptionStatus::Expired,
            'start_date' => Carbon::today()->subDays(395),
            'end_date'   => Carbon::today()->subDays(30),
        ]);

        // 5 — Tanpa subscription ──────────────────────────────────────────────
        User::updateOrCreate(
            ['email' => 'no-subscription@test.local'],
            [
                'external_user_id' => 'TEST-NO-SUB',
                'name'             => 'No Subscription User',
                'password'         => 'password',
            ],
        );
    }

    /** Replaces the user's most recent subscription record (idempotent on re-seed). */
    private function upsertSubscription(User $user, array $attributes): void
    {
        // On re-seed: delete existing subscriptions and recreate for clean state.
        Subscription::withTrashed()->where('user_id', $user->id)->forceDelete();

        Subscription::create(array_merge(['user_id' => $user->id, 'auto_renew' => false], $attributes));
    }
}
