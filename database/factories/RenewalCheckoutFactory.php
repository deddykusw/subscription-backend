<?php

namespace Database\Factories;

use App\Enums\RenewalCheckoutStatus;
use App\Enums\RenewalPeriod;
use App\Models\RenewalCheckout;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RenewalCheckout>
 */
class RenewalCheckoutFactory extends Factory
{
    protected $model = RenewalCheckout::class;

    public function definition(): array
    {
        $plan = SubscriptionPlan::query()->active()->orderBy('price')->first()
            ?? SubscriptionPlan::create([
                'name' => 'Monthly',
                'slug' => 'monthly',
                'price' => 50000,
                'currency' => 'IDR',
                'duration_days' => 30,
                'features' => [],
                'is_active' => true,
            ]);

        return [
            'user_id' => User::factory(),
            'subscription_plan_id' => $plan->id,
            'period' => RenewalPeriod::Month,
            'status' => RenewalCheckoutStatus::PendingPayment,
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'upload_deadline_at' => now()->addHours(48),
        ];
    }
}
