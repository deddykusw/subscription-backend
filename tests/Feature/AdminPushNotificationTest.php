<?php

namespace Tests\Feature;

use App\Models\FcmDeviceRegistration;
use App\Models\User;
use App\Services\FcmPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AdminPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_guest_cannot_view_push_page(): void
    {
        $this->get('/admin/push')->assertRedirect(route('admin.login'));
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin/push')->assertForbidden();
    }

    public function test_admin_can_view_push_page(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/admin/push');

        $response->assertOk();
        $this->assertStringContainsString('Admin\\/Push\\/Create', $response->getContent());
    }

    public function test_send_validates_required_fields(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/push', [])
            ->assertSessionHasErrors(['user_ids', 'title', 'body']);
    }

    public function test_send_reports_error_when_selected_users_have_no_fcm_tokens(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->from('/admin/push')
            ->post('/admin/push', [
                'user_ids' => [$target->id],
                'title' => 'Hello',
                'body' => 'World',
            ])
            ->assertRedirect('/admin/push')
            ->assertSessionHas('error');
    }

    public function test_admin_send_push_delegates_to_fcm_service(): void
    {
        $this->instance(FcmPushService::class, Mockery::mock(FcmPushService::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('sendToTokens')->once()->with(
                Mockery::on(fn ($t) => is_array($t) && count($t) === 1),
                'Judul uji',
                'Isi notifikasi uji',
                Mockery::type('array'),
            )->andReturn(['success' => 1, 'failed' => 0, 'errors' => []]);
        }));

        $admin = $this->admin();
        $target = User::factory()->create();
        FcmDeviceRegistration::create([
            'user_id' => $target->id,
            'fcm_token_hash' => hash('sha256', 'tok-'.str_repeat('a', 120)),
            'fcm_token' => 'tok-'.str_repeat('a', 120),
            'platform' => 'android',
            'app_version' => '1.0.0',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from('/admin/push')
            ->post('/admin/push', [
                'user_ids' => [$target->id],
                'title' => 'Judul uji',
                'body' => 'Isi notifikasi uji',
            ])
            ->assertRedirect('/admin/push')
            ->assertSessionHas('success');
    }

    public function test_send_fails_when_firebase_not_configured(): void
    {
        $this->instance(FcmPushService::class, Mockery::mock(FcmPushService::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(false);
            $m->shouldReceive('sendToTokens')->never();
        }));

        $admin = $this->admin();
        $target = User::factory()->create();
        FcmDeviceRegistration::create([
            'user_id' => $target->id,
            'fcm_token_hash' => hash('sha256', 'tok-'.str_repeat('b', 120)),
            'fcm_token' => 'tok-'.str_repeat('b', 120),
            'platform' => 'ios',
            'app_version' => null,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from('/admin/push')
            ->post('/admin/push', [
                'user_ids' => [$target->id],
                'title' => 'X',
                'body' => 'Y',
            ])
            ->assertRedirect('/admin/push')
            ->assertSessionHas('error');
    }
}
