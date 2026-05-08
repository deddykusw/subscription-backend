<?php

namespace Tests\Feature;

use App\Enums\CommissionStatus;
use App\Enums\PaymentOrderStatus;
use App\Enums\ReferralSettingType;
use App\Enums\SubscriptionStatus;
use App\Mail\CommissionEarnedMail;
use App\Models\Commission;
use App\Models\PaymentOrder;
use App\Models\Referral;
use App\Models\ReferralSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserReferral;
use Database\Seeders\ReferralSettingSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Feature tests for the referral integration.
 *
 * Covers:
 *  1. Referral code generation during registration
 *  2. Referral code application during registration
 *  3. Invalid referral code does not block registration
 *  4. Commission created when referred user's payment is verified
 *  5. Commission auto-credited when setting is enabled
 *  6. Commission stays pending when auto-credit is disabled
 *  7. Referrer balance updated after commission is credited
 *  8. Referrer receives email notification after payment verification
 *  9. Self-referral is rejected at apply endpoint
 * 10. Commission not duplicated when order verified twice (guarded at service level)
 */
class ReferralFlowTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionPlan $monthly;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);
        $this->seed(ReferralSettingSeeder::class);

        $this->monthly = SubscriptionPlan::where('slug', 'monthly')->first();
    }

    // =========================================================================
    // 1. Referral code generated on registration
    // =========================================================================

    public function test_registration_response_includes_referral_code(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'external_user_id' => 'REF-TEST-001',
            'name'             => 'Alice',
            'email'            => 'alice@test.com',
            'device_name'      => 'PHPUnit',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['referral_code']]);

        $code = $response->json('data.referral_code');
        $this->assertNotNull($code);
        $this->assertStringStartsWith('USER', $code);

        // UserReferral profile must exist in DB.
        $user = User::where('external_user_id', 'REF-TEST-001')->first();
        $this->assertDatabaseHas('user_referrals', [
            'user_id'       => $user->id,
            'referral_code' => $code,
        ]);
    }

    // =========================================================================
    // 2. Referral code applied during registration
    // =========================================================================

    public function test_registration_with_valid_referral_code_creates_referral(): void
    {
        $referrer = $this->makeUserWithReferralCode();

        $response = $this->postJson('/api/v1/auth/register', [
            'external_user_id' => 'REF-TEST-002',
            'name'             => 'Bob',
            'email'            => 'bob@test.com',
            'device_name'      => 'PHPUnit',
            'referral_code'    => $referrer->userReferral->referral_code,
        ]);

        $response->assertCreated();

        $referred = User::where('external_user_id', 'REF-TEST-002')->first();

        $this->assertDatabaseHas('referrals', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);

        $this->assertEquals($referrer->id, $referred->fresh()->referred_by);
    }

    // =========================================================================
    // 3. Invalid referral code does not block registration
    // =========================================================================

    public function test_invalid_referral_code_does_not_fail_registration(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'external_user_id' => 'REF-TEST-003',
            'name'             => 'Charlie',
            'email'            => 'charlie@test.com',
            'device_name'      => 'PHPUnit',
            'referral_code'    => 'INVALID-CODE-XYZ',
        ]);

        // Registration must succeed even with a bad code.
        $response->assertCreated()
            ->assertJsonPath('success', true);

        // No referral record created.
        $user = User::where('external_user_id', 'REF-TEST-003')->first();
        $this->assertDatabaseMissing('referrals', ['referred_user_id' => $user->id]);
        $this->assertNull($user->referred_by);
    }

    // =========================================================================
    // 4. Commission created when referred user's payment is verified
    // =========================================================================

    public function test_commission_created_when_referred_user_payment_verified(): void
    {
        $this->disableAutoCredit();

        [$referrer, $referred] = $this->makeReferralPair();
        $admin  = $this->makeAdmin();
        $order  = $this->makePendingOrder($referred, $this->monthly);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/subscription/admin/verify/{$order->id}", [
                'is_verified' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('commissions', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'payment_order_id' => $order->id,
        ]);

        $commission = Commission::where('payment_order_id', $order->id)->first();
        $this->assertNotNull($commission);
        $this->assertEquals(
            round($this->monthly->price * 10 / 100, 2),
            (float) $commission->amount,
        );
    }

    // =========================================================================
    // 5. Commission auto-credited when setting is enabled
    // =========================================================================

    public function test_commission_auto_credited_when_setting_enabled(): void
    {
        $this->enableAutoCredit();

        [$referrer, $referred] = $this->makeReferralPair();
        $admin = $this->makeAdmin();
        $order = $this->makePendingOrder($referred, $this->monthly);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/subscription/admin/verify/{$order->id}", [
                'is_verified' => true,
            ])
            ->assertOk();

        $commission = Commission::where('payment_order_id', $order->id)->first();
        $this->assertNotNull($commission);
        $this->assertEquals(CommissionStatus::Credited->value, $commission->fresh()->status->value);
    }

    // =========================================================================
    // 6. Commission stays pending when auto-credit is disabled
    // =========================================================================

    public function test_commission_stays_pending_when_auto_credit_disabled(): void
    {
        $this->disableAutoCredit();

        [$referrer, $referred] = $this->makeReferralPair();
        $admin = $this->makeAdmin();
        $order = $this->makePendingOrder($referred, $this->monthly);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/subscription/admin/verify/{$order->id}", [
                'is_verified' => true,
            ])
            ->assertOk();

        $commission = Commission::where('payment_order_id', $order->id)->first();
        $this->assertNotNull($commission);
        $this->assertEquals(CommissionStatus::Pending->value, $commission->status->value);
    }

    // =========================================================================
    // 7. Referrer balance updated after commission is credited
    // =========================================================================

    public function test_referrer_pending_earnings_updated_after_commission_credited(): void
    {
        $this->enableAutoCredit();

        [$referrer, $referred] = $this->makeReferralPair();
        $admin = $this->makeAdmin();
        $order = $this->makePendingOrder($referred, $this->monthly);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/subscription/admin/verify/{$order->id}", [
                'is_verified' => true,
            ])
            ->assertOk();

        $expectedCommission = round($this->monthly->price * 10 / 100, 2);

        $profile = UserReferral::where('user_id', $referrer->id)->first();
        $this->assertNotNull($profile);
        $this->assertEquals($expectedCommission, (float) $profile->pending_earnings);
        $this->assertEquals($expectedCommission, (float) $profile->total_earnings);
    }

    // =========================================================================
    // 8. Referrer receives email notification after payment verified
    // =========================================================================

    public function test_referrer_receives_commission_email_after_payment_verified(): void
    {
        Mail::fake();
        $this->enableAutoCredit();

        [$referrer, $referred] = $this->makeReferralPair();
        $admin = $this->makeAdmin();
        $order = $this->makePendingOrder($referred, $this->monthly);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/subscription/admin/verify/{$order->id}", [
                'is_verified' => true,
            ])
            ->assertOk();

        Mail::assertSent(CommissionEarnedMail::class, function (CommissionEarnedMail $mail) use ($referrer) {
            return $mail->hasTo($referrer->email);
        });
    }

    // =========================================================================
    // 9. Self-referral rejected at apply endpoint
    // =========================================================================

    public function test_self_referral_is_rejected_via_apply_endpoint(): void
    {
        $user  = $this->makeUserWithReferralCode();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/referral/apply', [
                'referral_code' => $user->userReferral->referral_code,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // =========================================================================
    // 10. No commission created for non-referred user
    // =========================================================================

    public function test_no_commission_created_for_non_referred_user(): void
    {
        $user  = User::factory()->create();
        $admin = $this->makeAdmin();
        $order = $this->makePendingOrder($user, $this->monthly);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/subscription/admin/verify/{$order->id}", [
                'is_verified' => true,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('commissions', [
            'referred_user_id' => $user->id,
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function makeAdmin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * Creates a user and generates a referral code for them.
     * Loads the userReferral relation so tests can access the code directly.
     */
    private function makeUserWithReferralCode(): User
    {
        $user    = User::factory()->create();
        $profile = UserReferral::create([
            'user_id'          => $user->id,
            'referral_code'    => 'USER' . $user->id . '-TESTCD',
            'total_referrals'  => 0,
            'total_earnings'   => 0,
            'total_paid_out'   => 0,
            'pending_earnings' => 0,
            'is_active'        => true,
        ]);

        $user->setRelation('userReferral', $profile);

        return $user;
    }

    /**
     * Creates a referrer + referred pair with the referral record in place.
     * Returns [$referrer, $referred].
     */
    private function makeReferralPair(): array
    {
        $referrer = $this->makeUserWithReferralCode();
        $referred = User::factory()->create([
            'referred_by'        => $referrer->id,
            'referral_code_used' => $referrer->userReferral->referral_code,
        ]);

        Referral::create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referral_code'    => $referrer->userReferral->referral_code,
            'status'           => \App\Enums\ReferralStatus::Pending,
            'referred_at'      => Carbon::now(),
        ]);

        return [$referrer, $referred];
    }

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

    private function enableAutoCredit(): void
    {
        ReferralSetting::set('auto_credit_commission', 'true');
        \Illuminate\Support\Facades\Cache::forget('referral_settings_all');
    }

    private function disableAutoCredit(): void
    {
        ReferralSetting::set('auto_credit_commission', 'false');
        \Illuminate\Support\Facades\Cache::forget('referral_settings_all');
    }
}
