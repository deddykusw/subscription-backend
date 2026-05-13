<?php

namespace Tests\Feature;

use App\Enums\RenewalCheckoutStatus;
use App\Enums\RenewalPeriod;
use App\Models\AttendanceProfile;
use App\Models\RenewalCheckout;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RenewalCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
    }

    private function fakeSesiAjaSuccessful(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'sesi-aja')) {
                return Http::response(['status' => true], 200);
            }

            return Http::response(['status' => false], 404);
        });
    }

    private function fakeSesiAjaUnauthorized(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'sesi-aja')) {
                return Http::response(['status' => false], 401);
            }

            return Http::response(['status' => false], 404);
        });
    }

    private function seedUserWithProfile(string $attendanceToken): User
    {
        $user = User::factory()->create();
        AttendanceProfile::create([
            'user_id' => $user->id,
            'attendance_token' => $attendanceToken,
            'token_obtained_at' => now(),
            'attendance_user_id' => 999991,
            'is_active' => true,
        ]);

        return $user;
    }

    public function test_payment_info_returns_401_when_attendance_session_invalid(): void
    {
        $this->fakeSesiAjaUnauthorized();
        $token = 'bad-attendance-token-'.str_repeat('a', 40);

        $response = $this->getJson(
            '/api/v1/renewals/payment-info?attendance_token='.urlencode($token).'&period=month',
        );

        $response->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_payment_info_returns_200_with_plan_snapshot(): void
    {
        $this->fakeSesiAjaSuccessful();
        $token = 'renewal-att-token-'.str_repeat('b', 40);
        $this->seedUserWithProfile($token);

        $response = $this->getJson(
            '/api/v1/renewals/payment-info?attendance_token='.urlencode($token).'&period=year',
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.period', 'year')
            ->assertJsonPath('data.currency', 'IDR')
            ->assertJsonStructure([
                'data' => [
                    'period',
                    'title',
                    'summary',
                    'amount',
                    'currency',
                    'payment_instructions',
                    'server_time',
                ],
            ]);
    }

    public function test_checkout_creates_pending_payment_record(): void
    {
        $this->fakeSesiAjaSuccessful();
        $token = 'renewal-checkout-'.str_repeat('c', 40);
        $user = $this->seedUserWithProfile($token);

        $response = $this->postJson('/api/v1/renewals/checkout', [
            'attendance_token' => $token,
            'period' => 'month',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.period', 'month');

        $checkoutId = $response->json('data.checkout_id');
        $this->assertNotEmpty($checkoutId);

        $this->assertDatabaseHas('renewal_checkouts', [
            'id' => $checkoutId,
            'user_id' => $user->id,
            'status' => RenewalCheckoutStatus::PendingPayment->value,
        ]);
    }

    public function test_checkout_rejected_409_when_pending_payment_exists(): void
    {
        $this->fakeSesiAjaSuccessful();
        $token = 'renewal-dup-pending-'.str_repeat('p', 35);
        $user = $this->seedUserWithProfile($token);

        RenewalCheckout::factory()->create([
            'user_id' => $user->id,
            'status' => RenewalCheckoutStatus::PendingPayment,
        ]);

        $response = $this->postJson('/api/v1/renewals/checkout', [
            'attendance_token' => $token,
            'period' => 'month',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_checkout_rejected_409_when_awaiting_review_exists(): void
    {
        $this->fakeSesiAjaSuccessful();
        $token = 'renewal-dup-review-'.str_repeat('q', 35);
        $user = $this->seedUserWithProfile($token);

        RenewalCheckout::factory()->create([
            'user_id' => $user->id,
            'status' => RenewalCheckoutStatus::AwaitingReview,
        ]);

        $response = $this->postJson('/api/v1/renewals/checkout', [
            'attendance_token' => $token,
            'period' => 'month',
        ]);

        $response->assertStatus(409);
    }

    public function test_checkout_allowed_after_previous_verified(): void
    {
        $this->fakeSesiAjaSuccessful();
        $token = 'renewal-after-ver-'.str_repeat('r', 35);
        $user = $this->seedUserWithProfile($token);

        RenewalCheckout::factory()->create([
            'user_id' => $user->id,
            'status' => RenewalCheckoutStatus::Verified,
        ]);

        $response = $this->postJson('/api/v1/renewals/checkout', [
            'attendance_token' => $token,
            'period' => 'month',
        ]);

        $response->assertCreated();
    }

    public function test_checkout_allowed_after_previous_rejected(): void
    {
        $this->fakeSesiAjaSuccessful();
        $token = 'renewal-after-rej-'.str_repeat('s', 35);
        $user = $this->seedUserWithProfile($token);

        RenewalCheckout::factory()->create([
            'user_id' => $user->id,
            'status' => RenewalCheckoutStatus::Rejected,
        ]);

        $response = $this->postJson('/api/v1/renewals/checkout', [
            'attendance_token' => $token,
            'period' => 'month',
        ]);

        $response->assertCreated();
    }

    public function test_upload_proof_forbidden_for_other_user_checkout(): void
    {
        $this->fakeSesiAjaSuccessful();
        Storage::fake('local');

        $tokenA = 'user-a-'.str_repeat('d', 50);
        $userA = $this->seedUserWithProfile($tokenA);

        $tokenB = 'user-b-'.str_repeat('e', 50);
        $this->seedUserWithProfile($tokenB);

        $checkout = RenewalCheckout::factory()->create([
            'user_id' => $userA->id,
            'period' => RenewalPeriod::Month,
            'status' => RenewalCheckoutStatus::PendingPayment,
        ]);

        $file = UploadedFile::fake()->image('proof.jpg', 100, 100);

        $response = $this->post(
            '/api/v1/renewals/'.$checkout->id.'/payment-proof',
            [
                'attendance_token' => $tokenB,
                'file' => $file,
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertForbidden();
    }

    public function test_upload_proof_moves_checkout_to_awaiting_review(): void
    {
        $this->fakeSesiAjaSuccessful();
        Storage::fake('local');

        $token = 'user-own-'.str_repeat('f', 50);
        $user = $this->seedUserWithProfile($token);

        $checkout = RenewalCheckout::factory()->create([
            'user_id' => $user->id,
            'period' => RenewalPeriod::Month,
            'status' => RenewalCheckoutStatus::PendingPayment,
        ]);

        $file = UploadedFile::fake()->image('proof.png', 80, 80);

        $response = $this->post(
            '/api/v1/renewals/'.$checkout->id.'/payment-proof',
            [
                'attendance_token' => $token,
                'file' => $file,
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertOk()
            ->assertJsonPath('data.status', RenewalCheckoutStatus::AwaitingReview->value)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('renewal_checkouts', [
            'id' => $checkout->id,
            'status' => RenewalCheckoutStatus::AwaitingReview->value,
        ]);

        $fresh = RenewalCheckout::findOrFail($checkout->id);
        $this->assertNotNull($fresh->proof_path);
        Storage::disk('local')->assertExists($fresh->proof_path);
    }

    public function test_list_returns_only_current_user_checkouts(): void
    {
        $this->fakeSesiAjaSuccessful();
        $token = 'renewal-list-'.str_repeat('h', 40);
        $user = $this->seedUserWithProfile($token);

        $other = User::factory()->create();
        RenewalCheckout::factory()->create(['user_id' => $other->id]);

        $mine = RenewalCheckout::factory()->create([
            'user_id' => $user->id,
            'status' => RenewalCheckoutStatus::PendingPayment,
        ]);

        $response = $this->getJson(
            '/api/v1/renewals/list?attendance_token='.urlencode($token).'&per_page=10',
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.checkout_id', $mine->id);

        $filtered = $this->getJson(
            '/api/v1/renewals/list?attendance_token='.urlencode($token).'&status=awaiting_review',
        );
        $filtered->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_second_upload_rejected_when_already_awaiting_review(): void
    {
        $this->fakeSesiAjaSuccessful();
        Storage::fake('local');

        $token = 'user-second-'.str_repeat('g', 45);
        $user = $this->seedUserWithProfile($token);

        $checkout = RenewalCheckout::factory()->create([
            'user_id' => $user->id,
            'period' => RenewalPeriod::Month,
            'status' => RenewalCheckoutStatus::AwaitingReview,
            'proof_disk' => 'local',
            'proof_path' => 'renewal-payment-proofs/'.$user->id.'/old.png',
        ]);

        $file = UploadedFile::fake()->image('proof2.jpg');

        $response = $this->post(
            '/api/v1/renewals/'.$checkout->id.'/payment-proof',
            [
                'attendance_token' => $token,
                'file' => $file,
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertForbidden();
    }
}
