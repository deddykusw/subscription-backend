<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageAdminController extends ApiController
{
    public function __construct(private readonly MessageService $messageService) {}

    /**
     * GET /api/v1/subscription/admin/messages
     *
     * Optional query `user_id` — when set, returns that user's thread (oldest first).
     * Without `user_id`, returns all messages newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'  => ['sometimes', 'integer', 'exists:users,id'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $perPage = min((int) ($validated['per_page'] ?? 30), 50);
        $userId  = isset($validated['user_id']) ? (int) $validated['user_id'] : null;

        $paginator = $this->messageService->listForAdmin($userId, $perPage);

        return $this->success([
            'items' => collect($paginator->items())->map(fn ($m) => $this->formatAdminMessage($m))->values(),
            'meta'  => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'has_more'     => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * POST /api/v1/subscription/admin/messages
     *
     * Body: { "user_id": int, "message": "..." } — reply to that user (non-admin only).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'message' => ['required', 'string', 'min:1', 'max:10000'],
        ]);

        try {
            $msg = $this->messageService->sendFromAdmin(
                $request->user(),
                (int) $validated['user_id'],
                trim($validated['message']),
            );
            $msg->load(['user:id,name,email', 'adminUser:id,name']);

            return $this->success($this->formatAdminMessage($msg), 'Balasan terkirim.', 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/v1/subscription/admin/messages/mark-read
     *
     * Body: { "user_id": int } — mark all user-originated messages in that thread as read by admin.
     */
    public function markRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $updated = $this->messageService->markReadByAdmin((int) $validated['user_id']);

        return $this->success(['marked_count' => $updated]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAdminMessage(Message $m): array
    {
        return [
            'id'               => $m->id,
            'user_id'          => $m->user_id,
            'body'             => $m->body,
            'sender_is_admin'  => $m->sender_is_admin,
            'read_by_user_at'  => $m->read_by_user_at?->toIso8601String(),
            'read_by_admin_at' => $m->read_by_admin_at?->toIso8601String(),
            'created_at'       => $m->created_at->toIso8601String(),
            'user'             => $m->relationLoaded('user') && $m->user
                ? [
                    'id'       => $m->user->id,
                    'name'     => $m->user->name,
                    'email'    => $m->user->email,
                    'username' => $m->user->username,
                ]
                : null,
            'admin'            => $m->sender_is_admin && $m->adminUser
                ? ['id' => $m->adminUser->id, 'name' => $m->adminUser->name]
                : null,
        ];
    }
}
