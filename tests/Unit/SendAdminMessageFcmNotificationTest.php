<?php

namespace Tests\Unit;

use App\Models\Message;
use App\Models\User;
use App\Services\FcmPushService;
use App\Services\SendAdminMessageFcmNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SendAdminMessageFcmNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_data_payload_contains_required_type_and_message_id(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['is_admin' => false]);

        $message = Message::create([
            'user_id' => $target->id,
            'sender_is_admin' => true,
            'admin_user_id' => $admin->id,
            'body' => 'Isi percakapan',
            'read_by_user_at' => null,
            'read_by_admin_at' => now(),
        ]);

        $data = SendAdminMessageFcmNotification::dataPayload($message);

        $this->assertSame('subscription_admin_message', $data['type']);
        $this->assertSame((string) $message->id, $data['message_id']);
        $this->assertArrayHasKey('body', $data);
        $this->assertIsString($data['body']);
    }

    public function test_notification_title_uses_admin_name_or_fallback(): void
    {
        $this->assertSame('Pesan dari Budi', SendAdminMessageFcmNotification::notificationTitle('Budi'));
        $this->assertSame('Pesan dari Admin', SendAdminMessageFcmNotification::notificationTitle(null));
        $this->assertSame('Pesan dari Admin', SendAdminMessageFcmNotification::notificationTitle(''));
    }

    public function test_notification_body_truncates_long_text(): void
    {
        $long = str_repeat('あ', 500);
        $out = SendAdminMessageFcmNotification::notificationBody($long);
        $this->assertLessThanOrEqual(381, mb_strlen($out));
        $this->assertStringEndsWith('…', $out);
    }

    public function test_send_for_message_id_skips_when_no_fcm_tokens_without_calling_fcm_http(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['is_admin' => false]);

        $message = Message::create([
            'user_id' => $target->id,
            'sender_is_admin' => true,
            'admin_user_id' => $admin->id,
            'body' => 'Halo',
            'read_by_user_at' => null,
            'read_by_admin_at' => now(),
        ]);

        $fcm = Mockery::mock(FcmPushService::class);
        $fcm->shouldReceive('isConfigured')->never();
        $fcm->shouldReceive('sendNotificationWithPruning')->never();

        $sender = new SendAdminMessageFcmNotification($fcm);
        $sender->sendForMessageId($message->id);

        $this->assertModelExists($message);
    }
}
