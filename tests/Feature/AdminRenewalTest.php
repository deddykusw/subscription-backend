<?php

namespace Tests\Feature;

use App\Enums\RenewalCheckoutStatus;
use App\Enums\SubscriptionStatus;
use App\Models\RenewalCheckout;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_renewals_index(): void
    {
        $this->get(route('admin.renewals.index'))->assertRedirect(route('admin.login'));
    }

    public function test_non_admin_cannot_access_renewals_index(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user);
        $this->get(route('admin.renewals.index'))->assertForbidden();
    }

    public function test_admin_can_list_renewals(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $response = $this->get(route('admin.renewals.index'));
        $response->assertOk();
        $this->assertStringContainsString('Admin\\/Renewals\\/Index', $response->getContent());
    }

    public function test_admin_can_approve_renewal_and_extend_subscription(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        Storage::fake('local');

        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $plan = SubscriptionPlan::where('slug', 'monthly')->firstOrFail();

        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'auto_renew' => false,
        ]);

        $path = 'renewal-payment-proofs/'.$user->id.'/proof.jpg';
        Storage::disk('local')->put($path, 'fake-image');

        $checkout = RenewalCheckout::factory()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => RenewalCheckoutStatus::AwaitingReview,
            'proof_disk' => 'local',
            'proof_path' => $path,
        ]);

        $this->actingAs($admin);

        $originalEnd = $sub->fresh()->end_date->copy();

        $this->post(route('admin.renewals.verify', $checkout), [
            'is_verified' => true,
        ])->assertSessionHas('success');

        $checkout->refresh();
        $this->assertSame(RenewalCheckoutStatus::Verified, $checkout->status);
        $this->assertNotNull($checkout->reviewed_at);

        $sub->refresh();
        $this->assertEquals(
            $originalEnd->copy()->addDays((int) $plan->duration_days)->toDateString(),
            $sub->end_date->toDateString(),
        );
    }

    public function test_admin_can_stream_proof_file(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        Storage::fake('local');

        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $plan = SubscriptionPlan::firstOrFail();
        $path = 'renewal-payment-proofs/'.$user->id.'/x.png';
        Storage::disk('local')->put($path, 'binary');

        $checkout = RenewalCheckout::factory()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => RenewalCheckoutStatus::AwaitingReview,
            'proof_disk' => 'local',
            'proof_path' => $path,
        ]);

        $this->actingAs($admin);
        $this->get(route('admin.renewals.proof', $checkout))->assertOk();
    }
}
