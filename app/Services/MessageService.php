<?php

namespace App\Services;

use App\Jobs\SendAdminMessageFcmPushJob;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MessageService
{
    public function sendFromUser(User $user, string $body): Message
    {
        if ($user->is_admin) {
            throw new \InvalidArgumentException('Akun administrator tidak dapat mengirim pesan lewat saluran ini.');
        }

        return Message::create([
            'user_id' => $user->id,
            'sender_is_admin' => false,
            'admin_user_id' => null,
            'body' => $body,
            'read_by_user_at' => now(),
            'read_by_admin_at' => null,
        ]);
    }

    public function sendFromAdmin(User $admin, int $targetUserId, string $body): Message
    {
        if (! $admin->is_admin) {
            throw new \InvalidArgumentException('Hanya administrator yang dapat membalas.');
        }

        $target = User::query()->findOrFail($targetUserId);

        if ($target->is_admin) {
            throw new \InvalidArgumentException('Tidak dapat mengirim pesan ke akun administrator lain.');
        }

        $message = Message::create([
            'user_id' => $target->id,
            'sender_is_admin' => true,
            'admin_user_id' => $admin->id,
            'body' => $body,
            'read_by_user_at' => null,
            'read_by_admin_at' => now(),
        ]);

        // FCM: Laravel 13 + queue (see .env QUEUE_CONNECTION). Runs after DB commit when inside a transaction.
        SendAdminMessageFcmPushJob::dispatch($message->id)->afterCommit();

        return $message;
    }

    /** @return LengthAwarePaginator<int, Message> */
    public function threadForUser(User $user, int $perPage): LengthAwarePaginator
    {
        return Message::query()
            ->where('user_id', $user->id)
            ->with(['adminUser:id,name'])
            ->orderBy('created_at')
            ->paginate($perPage);
    }

    /** @return LengthAwarePaginator<int, Message> */
    public function listForAdmin(?int $userId, int $perPage): LengthAwarePaginator
    {
        $q = Message::query()
            ->with(['user:id,name,email,username', 'adminUser:id,name']);

        if ($userId !== null) {
            $q->where('user_id', $userId)->orderBy('created_at');
        } else {
            $q->orderByDesc('created_at');
        }

        return $q->paginate($perPage);
    }

    public function markReadByUser(User $user): int
    {
        return Message::query()
            ->where('user_id', $user->id)
            ->where('sender_is_admin', true)
            ->whereNull('read_by_user_at')
            ->update(['read_by_user_at' => now()]);
    }

    public function markReadByAdmin(int $targetUserId): int
    {
        return Message::query()
            ->where('user_id', $targetUserId)
            ->where('sender_is_admin', false)
            ->whereNull('read_by_admin_at')
            ->update(['read_by_admin_at' => now()]);
    }

    /**
     * One row per user who has at least one message, for admin inbox (newest activity first).
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function conversationSummariesForAdmin(int $perPage = 20): LengthAwarePaginator
    {
        $sub = Message::query()
            ->select('user_id')
            ->selectRaw('MAX(created_at) as last_message_at')
            ->selectRaw('SUM(CASE WHEN sender_is_admin = 0 AND read_by_admin_at IS NULL THEN 1 ELSE 0 END) as unread_for_admin')
            ->groupBy('user_id');

        return DB::query()
            ->fromSub($sub, 'threads')
            ->orderByDesc('last_message_at')
            ->paginate($perPage);
    }
}
