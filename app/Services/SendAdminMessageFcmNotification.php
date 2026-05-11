<?php

namespace App\Services;

use App\Models\FcmDeviceRegistration;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

/**
 * Builds the FCM contract for "admin → user" chat messages and sends to all
 * registered devices for the recipient user (async via {@see SendAdminMessageFcmPushJob}).
 *
 * Flutter listens for {@see SendAdminMessageFcmNotification::DATA_TYPE} in the data payload.
 */
class SendAdminMessageFcmNotification
{
    public const DATA_TYPE = 'subscription_admin_message';

    private const NOTIFICATION_BODY_MAX = 380;

    private const DATA_BODY_MAX = 500;

    public function __construct(
        private readonly FcmPushService $fcmPushService,
    ) {}

    /**
     * Loads the message, resolves FCM tokens for the recipient, and sends (with invalid-token pruning).
     * Safe to run from a queue worker; logs without raw tokens.
     */
    public function sendForMessageId(int $messageId): void
    {
        $message = Message::query()->with(['adminUser:id,name'])->find($messageId);
        if ($message === null || ! $message->sender_is_admin) {
            return;
        }

        $tokens = FcmDeviceRegistration::query()
            ->where('user_id', $message->user_id)
            ->pluck('fcm_token')
            ->unique()
            ->values()
            ->all();

        if ($tokens === []) {
            Log::info('admin_message_fcm_skipped_no_tokens', [
                'message_id' => $messageId,
                'user_id' => $message->user_id,
            ]);

            return;
        }

        if (! $this->fcmPushService->isConfigured()) {
            Log::warning('admin_message_fcm_skipped_firebase_unconfigured', [
                'message_id' => $messageId,
                'user_id' => $message->user_id,
                'device_count' => count($tokens),
            ]);

            return;
        }

        $title = self::notificationTitle($message->adminUser?->name);
        $body = self::notificationBody($message->body);
        $data = self::dataPayload($message);

        $result = $this->fcmPushService->sendNotificationWithPruning(
            $tokens,
            $title,
            $body,
            $data,
            (int) $message->user_id,
        );

        Log::info('admin_message_fcm_finished', [
            'message_id' => $messageId,
            'user_id' => $message->user_id,
            'success' => $result['success'],
            'failed' => $result['failed'],
            'pruned' => $result['pruned'],
        ]);
    }

    public static function notificationTitle(?string $adminName): string
    {
        $name = ($adminName !== null && $adminName !== '') ? $adminName : 'Admin';

        return 'Pesan dari '.$name;
    }

    public static function notificationBody(string $messageBody): string
    {
        return self::truncateUtf8($messageBody, self::NOTIFICATION_BODY_MAX);
    }

    /**
     * @return array<string, string>
     */
    public static function dataPayload(Message $message): array
    {
        return [
            'type' => self::DATA_TYPE,
            'message_id' => (string) $message->id,
            'body' => self::truncateUtf8($message->body, self::DATA_BODY_MAX),
        ];
    }

    public static function truncateUtf8(string $text, int $maxChars): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text) <= $maxChars) {
                return $text;
            }

            return mb_substr($text, 0, $maxChars).'…';
        }

        if (strlen($text) <= $maxChars) {
            return $text;
        }

        return substr($text, 0, $maxChars).'…';
    }
}
