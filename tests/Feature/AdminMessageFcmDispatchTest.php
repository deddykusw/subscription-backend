<?php

namespace Tests\Feature;

use App\Jobs\SendAdminMessageFcmPushJob;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AdminMessageFcmDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_from_admin_dispatches_fcm_job(): void
    {
        Bus::fake();

        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['is_admin' => false]);

        app(MessageService::class)->sendFromAdmin($admin, $target->id, 'Pesan admin');

        Bus::assertDispatched(SendAdminMessageFcmPushJob::class, fn (SendAdminMessageFcmPushJob $job) => $job->messageId > 0);
    }
}
