<?php

namespace Tests\Unit;

use App\Models\Message;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MessageServiceThreadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_thread_for_user_first_page_is_latest_per_page_sorted_oldest_to_newest_in_payload(): void
    {
        Carbon::setTestNow('2026-05-11 12:00:00');

        $user = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->admin()->create();

        foreach ([1, 2, 3, 4, 5] as $i) {
            $m = Message::create([
                'user_id' => $user->id,
                'sender_is_admin' => true,
                'admin_user_id' => $admin->id,
                'body' => "msg-{$i}",
                'read_by_user_at' => null,
                'read_by_admin_at' => now(),
            ]);
            $m->forceFill(['created_at' => now()->copy()->addMinutes($i)])->saveQuietly();
        }

        $this->actingAs($user);
        request()->merge(['page' => 1]);

        $paginator = app(MessageService::class)->threadForUser($user, 3);
        $items = collect($paginator->items())->values();

        $this->assertCount(3, $items);
        // Newest window: messages 3,4,5 by time → ascending in response: 3 then 4 then 5
        $this->assertSame('msg-3', $items[0]->body);
        $this->assertSame('msg-4', $items[1]->body);
        $this->assertSame('msg-5', $items[2]->body);
        $this->assertTrue($items[0]->created_at->lessThanOrEqualTo($items[1]->created_at));
        $this->assertTrue($items[1]->created_at->lessThanOrEqualTo($items[2]->created_at));
    }
}
