<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\SendAdminMessageFcmNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends FCM for an admin-authored {@see Message} after it is persisted.
 *
 * Stack: Laravel 13 + queue (database/redis/etc.) + MySQL/SQLite.
 * Retries with backoff for transient Google/FCM errors.
 */
class SendAdminMessageFcmPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var list<int> */
    public $backoff = [10, 30, 60, 120];

    public int $tries = 5;

    public function __construct(public int $messageId) {}

    public function handle(SendAdminMessageFcmNotification $sender): void
    {
        $sender->sendForMessageId($this->messageId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('admin_message_fcm_job_failed', [
            'message_id' => $this->messageId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
