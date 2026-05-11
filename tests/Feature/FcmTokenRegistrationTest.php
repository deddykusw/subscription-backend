<?php

namespace Tests\Feature;

use App\Models\AttendanceProfile;
use App\Models\FcmDeviceRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FcmTokenRegistrationTest extends TestCase
{
    use RefreshDatabase;

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
                return Http::response(['status' => false, 'message' => 'Unauthorized'], 401);
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

    private function validFcmToken(): string
    {
        return str_repeat('a', 142);
    }

    public function test_happy_path_registers_fcm_token(): void
    {
        $this->fakeSesiAjaSuccessful();
        $attendance = 'attendance-test-token-'.str_repeat('x', 40);
        $user = $this->seedUserWithProfile($attendance);
        $fcm = $this->validFcmToken();

        $response = $this->postJson('/api/v1/notifications/fcm-token', [
            'attendance_token' => $attendance,
            'fcm_token' => $fcm,
            'platform' => 'android',
            'app_version' => '1.2.3',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'FCM token registered')
            ->assertJsonStructure(['data' => ['id', 'updated_at']]);

        $this->assertDatabaseHas('fcm_device_registrations', [
            'user_id' => $user->id,
            'platform' => 'android',
            'app_version' => '1.2.3',
        ]);
    }

    public function test_idempotent_second_post_same_token(): void
    {
        $this->fakeSesiAjaSuccessful();
        $attendance = 'attendance-idem-'.str_repeat('y', 36);
        $user = $this->seedUserWithProfile($attendance);
        $fcm = $this->validFcmToken();

        $payload = [
            'attendance_token' => $attendance,
            'fcm_token' => $fcm,
            'platform' => 'ios',
            'app_version' => '2.0.0',
        ];

        $this->postJson('/api/v1/notifications/fcm-token', $payload)->assertOk()->assertJsonPath('success', true);
        $this->postJson('/api/v1/notifications/fcm-token', $payload)->assertOk()->assertJsonPath('success', true);

        $this->assertEquals(1, FcmDeviceRegistration::where('user_id', $user->id)->count());
    }

    public function test_invalid_attendance_token_returns_401(): void
    {
        $this->fakeSesiAjaUnauthorized();

        $this->postJson('/api/v1/notifications/fcm-token', [
            'attendance_token' => 'no-matching-profile-token-'.str_repeat('z', 30),
            'fcm_token' => $this->validFcmToken(),
            'platform' => 'android',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);

        $this->assertEquals(0, FcmDeviceRegistration::count());
    }

    public function test_valid_sesi_but_missing_profile_returns_401(): void
    {
        $this->fakeSesiAjaSuccessful();
        $orphanToken = 'orphan-attendance-'.str_repeat('q', 36);

        $this->postJson('/api/v1/notifications/fcm-token', [
            'attendance_token' => $orphanToken,
            'fcm_token' => $this->validFcmToken(),
            'platform' => 'android',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);

        $this->assertEquals(0, FcmDeviceRegistration::count());
    }

    public function test_validation_fails_on_empty_body_fields(): void
    {
        $this->fakeSesiAjaSuccessful();

        $this->postJson('/api/v1/notifications/fcm-token', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_validation_fails_when_fcm_token_too_short(): void
    {
        $this->fakeSesiAjaSuccessful();
        $attendance = 'attendance-short-fcm-'.str_repeat('s', 32);
        $this->seedUserWithProfile($attendance);

        $this->postJson('/api/v1/notifications/fcm-token', [
            'attendance_token' => $attendance,
            'fcm_token' => str_repeat('b', 20),
            'platform' => 'android',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_unknown_platform_is_normalized_to_other(): void
    {
        $this->fakeSesiAjaSuccessful();
        $attendance = 'attendance-platform-'.str_repeat('p', 32);
        $user = $this->seedUserWithProfile($attendance);

        $this->postJson('/api/v1/notifications/fcm-token', [
            'attendance_token' => $attendance,
            'fcm_token' => $this->validFcmToken(),
            'platform' => 'watchOS',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('fcm_device_registrations', [
            'user_id' => $user->id,
            'platform' => 'other',
        ]);
    }

    public function test_rejects_oversized_json_body_with_413(): void
    {
        $large = str_repeat('a', 20_000);

        $response = $this->call('POST', '/api/v1/notifications/fcm-token', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{"x":"'.$large.'"}');

        $response->assertStatus(413)
            ->assertJsonPath('success', false);
    }
}
