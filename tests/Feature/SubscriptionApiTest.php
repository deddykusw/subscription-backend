<?php

namespace Tests\Feature;

use App\Enums\PaymentOrderStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Middleware\CheckSubscription;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Feature tests for the subscription API.
 *
 * Each test runs against an in-memory SQLite database (see phpunit.xml).
 * RefreshDatabase wraps each test in a transaction that is rolled back
 * after the test completes, so state never leaks between tests.
 *
 * Test coverage:
 *  1. User registration auto-creates a 7-day free trial
 *  2. Trial countdown decreases as time passes
 *  3. Subscription activates after admin payment verification
 *  4. Expired subscription shows isValid=false and CheckSubscription blocks access
 *  5. Payment order creation returns order details and PayPal instructions
 *  6. Admin can verify (approve) and reject payment orders
 */
class SubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionPlan $monthly;
    private SubscriptionPlan $yearly;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);

        $this->monthly = SubscriptionPlan::where('slug', 'monthly')->first();
        $this->yearly  = SubscriptionPlan::where('slug', 'yearly')->first();
    }

    // =========================================================================
    // 1. Registration auto-creates trial
    // =========================================================================

    public function test_user_registration_auto_creates_seven_day_trial(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'external_user_id' => 'REG-TEST-001',
            'name'             => 'New User',
            'email'            => 'newuser@test.com',
            'device_name'      => 'PHPUnit',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.subscription.status', 'trial')
            ->assertJsonPath('data.subscription.remainingDays', 7)
            ->assertJsonPath('data.subscription.isActive', true);

        // SQLite stores date columns as 'Y-m-d H:i:s' internally, so we query
        // via Eloquent rather than comparing raw strings in assertDatabaseHas.
        $created = Subscription::where('status', SubscriptionStatus::Trial)->first();
        $this->assertNotNull($created);
        $this->assertEquals(Carbon::today()->addDays(7)->toDateString(), $created->end_date->toDateString());
    }

    public function test_registration_fails_with_duplicate_external_user_id(): void
    {
        $this->makeUser(['external_user_id' => 'DUP-001']);

        $this->postJson('/api/v1/auth/register', [
            'external_user_id' => 'DUP-001',
            'name'             => 'Duplicate',
            'email'            => 'dup@test.com',
            'device_name'      => 'PHPUnit',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['external_user_id']);
    }

    // =========================================================================
    // 2. Trial countdown calculation
    // =========================================================================

    public function test_trial_countdown_reflects_remaining_days(): void
    {
        $user  = $this->makeUserWithTrial();
        $token = $user->createToken('test')->plainTextToken;

        // Day 0 — just created, 7 days remaining
        $this->withToken($token)
            ->getJson("/api/v1/subscription/trial/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.remainingDays', 7)
            ->assertJsonPath('data.trialCountdown', '7 days remaining')
            ->assertJsonPath('data.isOnTrial', true);

        // Travel 3 days forward — 4 days remaining
        $this->travel(3)->days();

        $this->withToken($token)
            ->getJson("/api/v1/subscription/trial/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.remainingDays', 4)
            ->assertJsonPath('data.trialCountdown', '4 days remaining');
    }

    public function test_trial_countdown_shows_expires_today_on_last_day(): void
    {
        $user  = $this->makeUserWithTrial();
        $token = $user->createToken('test')->plainTextToken;

        // Travel to the last day of trial
        $this->travel(7)->days();

        $this->withToken($token)
            ->getJson("/api/v1/subscription/trial/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.remainingDays', 0)
            ->assertJsonPath('data.trialCountdown', 'Expires today');
    }

    // =========================================================================
    // 3. Subscription activation after payment verification
    // =========================================================================

    public function test_subscription_activates_after_admin_payment_verification(): void
    {
        $user    = $this->makeUserWithTrial();
        $admin   = $this->makeAdmin();
        $order   = $this->makePendingOrder($user, $this->monthly);

        // Enrich the order with proof details (simulates submitProof)
        $order->update([
            'transaction_id' => 'TXN-VERIFY-001',
            'paypal_email'   => 'buyer@paypal.test',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/subscription/admin/verify/{$order->id}", [
                'is_verified' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order.status', 'verified')
            ->assertJsonPath('data.order.subscription.status', 'active');

        // The user should now have an active subscription
        $this->assertTrue($user->fresh()->hasActiveSubscription());

        // The previously active trial should have been cancelled
        $this->assertDatabaseMissing('subscriptions', [
            'user_id' => $user->id,
            'status'  => SubscriptionStatus::Trial->value,
        ]);
    }

    public function test_activating_subscription_sets_correct_end_date(): void
    {
        $user  = $this->makeUser();
        $admin = $this->makeAdmin();
        $order = $this->makePendingOrder($user, $this->monthly);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/subscription/admin/verify/{$order->id}", [
                'is_verified' => true,
            ])
            ->assertOk();

        $subscription = $user->fresh()->getCurrentSubscription();
        $expectedEnd  = Carbon::today()->addDays($this->monthly->duration_days)->toDateString();

        $this->assertNotNull($subscription);
        $this->assertEquals($expectedEnd, $subscription->end_date->toDateString());
    }

    // =========================================================================
    // 4. Expired subscription blocks access
    // =========================================================================

    public function test_check_endpoint_returns_invalid_for_expired_subscription(): void
    {
        $user  = $this->makeUserWithExpiredSubscription();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/subscription/check/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.isValid', false)
            ->assertJsonPath('success', true);
    }

    public function test_check_subscription_middleware_blocks_expired_user(): void
    {
        $user       = $this->makeUserWithExpiredSubscription();
        $middleware  = new CheckSubscription();

        $request = Request::create('http://localhost/api/v1/attendance/report', 'GET');
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals(
            'SUBSCRIPTION_REQUIRED',
            json_decode($response->getContent(), true)['code'],
        );
    }

    public function test_check_subscription_middleware_allows_plans_for_expired_user(): void
    {
        $user       = $this->makeUserWithExpiredSubscription();
        $middleware  = new CheckSubscription();

        $request = Request::create('http://localhost/api/v1/subscription/plans', 'GET');
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_check_subscription_middleware_allows_order_for_expired_user(): void
    {
        $user       = $this->makeUserWithExpiredSubscription();
        $middleware  = new CheckSubscription();

        $request = Request::create('http://localhost/api/v1/subscription/order', 'POST');
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    // =========================================================================
    // 5. Payment order creation
    // =========================================================================

    public function test_payment_order_creation_returns_order_and_instructions(): void
    {
        $user  = $this->makeUserWithTrial();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/v1/subscription/order', [
                'user_id' => $user->id,
                'plan_id' => $this->monthly->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.order.status', 'pending')
            ->assertJsonPath('data.order.amount', '50000.00')
            ->assertJsonPath('data.order.currency', 'IDR')
            ->assertJsonStructure([
                'data' => [
                    'order' => ['id', 'status', 'amount', 'currency', 'paymentMethod'],
                    'instructions' => [
                        'paypal_receiver_email',
                        'amount',
                        'steps',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'plan_id' => $this->monthly->id,
            'status'  => PaymentOrderStatus::Pending->value,
        ]);
    }

    public function test_duplicate_pending_order_is_rejected(): void
    {
        $user  = $this->makeUserWithTrial();
        $token = $user->createToken('test')->plainTextToken;

        // First order succeeds
        $this->withToken($token)
            ->postJson('/api/v1/subscription/order', [
                'user_id' => $user->id,
                'plan_id' => $this->monthly->id,
            ])->assertCreated();

        // Duplicate order for same plan fails
        $this->withToken($token)
            ->postJson('/api/v1/subscription/order', [
                'user_id' => $user->id,
                'plan_id' => $this->monthly->id,
            ])->assertStatus(422)
              ->assertJsonPath('success', false);
    }

    // =========================================================================
    // 6. Admin payment verification (approve + reject)
    // =========================================================================

    public function test_admin_can_approve_payment_and_subscription_activates(): void
    {
        $user  = $this->makeUser();
        $admin = $this->makeAdmin();
        $order = $this->makePendingOrder($user, $this->yearly);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/subscription/admin/verify/{$order->id}", [
                'is_verified' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.order.status', 'verified')
            ->assertJsonPath('data.order.isFinal', true);

        $this->assertDatabaseHas('payment_orders', [
            'id'     => $order->id,
            'status' => PaymentOrderStatus::Verified->value,
        ]);

        $this->assertTrue($user->fresh()->hasActiveSubscription());
    }

    public function test_admin_can_reject_payment_with_reason(): void
    {
        $user    = $this->makeUser();
        $admin   = $this->makeAdmin();
        $order   = $this->makePendingOrder($user, $this->monthly);
        $reason  = 'Transaction ID not found in PayPal records.';

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/subscription/admin/verify/{$order->id}", [
                'is_verified' => false,
                'notes'       => $reason,
            ])
            ->assertOk()
            ->assertJsonPath('data.order.status', 'rejected')
            ->assertJsonPath('data.order.notes', $reason);

        $this->assertFalse($user->fresh()->hasActiveSubscription());
        $this->assertFalse($user->fresh()->isOnTrial());
    }

    public function test_rejection_requires_notes(): void
    {
        $admin = $this->makeAdmin();
        $order = $this->makePendingOrder($this->makeUser(), $this->monthly);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/subscription/admin/verify/{$order->id}", [
                'is_verified' => false,
                // notes intentionally omitted
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notes']);
    }

    public function test_non_admin_cannot_verify_payment(): void
    {
        $user  = $this->makeUser();
        $order = $this->makePendingOrder($user, $this->monthly);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/subscription/admin/verify/{$order->id}", [
                'is_verified' => true,
            ])
            ->assertForbidden();
    }

    public function test_cannot_verify_already_verified_order(): void
    {
        $admin = $this->makeAdmin();
        $user  = $this->makeUser();
        $order = $this->makePendingOrder($user, $this->monthly);

        // First verification succeeds
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/subscription/admin/verify/{$order->id}", [
                'is_verified' => true,
            ])->assertOk();

        // Second verification fails — order is no longer pending
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/subscription/admin/verify/{$order->id}", [
                'is_verified' => true,
            ])->assertStatus(422)
              ->assertJsonPath('success', false);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /** Creates a plain user with no subscription. */
    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    /** Creates an admin user. */
    private function makeAdmin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * Creates a user with an active 7-day trial subscription.
     * Mirrors what SubscriptionService::createTrialSubscription() produces.
     */
    private function makeUserWithTrial(): User
    {
        $user = $this->makeUser();

        Subscription::create([
            'user_id'          => $user->id,
            'plan_id'          => $this->monthly->id,
            'status'           => SubscriptionStatus::Trial,
            'trial_start_date' => Carbon::today(),
            'trial_end_date'   => Carbon::today()->addDays(7),
            'start_date'       => Carbon::today(),
            'end_date'         => Carbon::today()->addDays(7),
            'auto_renew'       => false,
        ]);

        return $user;
    }

    /**
     * Creates a user whose subscription ended in the past.
     * Used to verify that expired users are blocked by CheckSubscription.
     */
    private function makeUserWithExpiredSubscription(): User
    {
        $user = $this->makeUser();

        Subscription::create([
            'user_id'    => $user->id,
            'plan_id'    => $this->monthly->id,
            'status'     => SubscriptionStatus::Expired,
            'start_date' => Carbon::today()->subDays(37),
            'end_date'   => Carbon::today()->subDays(7),
            'auto_renew' => false,
        ]);

        return $user;
    }

    /**
     * Creates a pending PaymentOrder for the given user and plan.
     * Replicates what PaymentController::createOrder() persists.
     */
    private function makePendingOrder(User $user, SubscriptionPlan $plan): PaymentOrder
    {
        return PaymentOrder::create([
            'user_id'        => $user->id,
            'plan_id'        => $plan->id,
            'amount'         => $plan->price,
            'currency'       => $plan->currency,
            'payment_method' => 'paypal',
            'status'         => PaymentOrderStatus::Pending,
        ]);
    }
}
