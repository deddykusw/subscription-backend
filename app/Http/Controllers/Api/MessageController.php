<?php

namespace App\Http\Controllers\Api;

use App\Models\Message;
use App\Services\ExternalAuthService;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends ApiController
{
    public function __construct(
        private readonly ExternalAuthService $externalAuthService,
        private readonly MessageService $messageService,
    ) {}

    /**
     * POST /api/v1/messages — user sends a message to admin (attendance_token).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attendance_token' => ['required', 'string'],
            'message' => ['required', 'string', 'min:1', 'max:10000'],
        ]);

        try {
            $user = $this->externalAuthService->resolveLocalUserFromAttendanceToken(
                $validated['attendance_token'],
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 401);
        }

        try {
            $msg = $this->messageService->sendFromUser($user, trim($validated['message']));
            $msg->load('adminUser:id,name');

            return $this->success($this->formatMessage($msg), 'Pesan terkirim.', 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * GET /api/v1/messages — paginated thread for user (attendance_token query).
     *
     * Halaman 1 berisi {@code per_page} pesan **terbaru**; halaman berikutnya berisi pesan lebih lama.
     * Dalam {@code items}, urutan per halaman: {@code created_at} naik (bawah = terbaru di jendela itu).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attendance_token' => ['required', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $user = $this->externalAuthService->resolveLocalUserFromAttendanceToken(
                $validated['attendance_token'],
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 401);
        }

        $perPage = min((int) ($validated['per_page'] ?? 20), 50);
        $paginator = $this->messageService->threadForUser($user, $perPage);

        return $this->success([
            'items' => collect($paginator->items())->map(fn ($m) => $this->formatMessage($m))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * POST /api/v1/messages/mark-read — user marks admin replies as read.
     */
    public function markRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attendance_token' => ['required', 'string'],
        ]);

        try {
            $user = $this->externalAuthService->resolveLocalUserFromAttendanceToken(
                $validated['attendance_token'],
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 401);
        }

        $updated = $this->messageService->markReadByUser($user);

        return $this->success(['marked_count' => $updated]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMessage(Message $m): array
    {
        return [
            'id' => $m->id,
            'body' => $m->body,
            'sender_is_admin' => $m->sender_is_admin,
            'read_by_user_at' => $m->read_by_user_at?->toIso8601String(),
            'read_by_admin_at' => $m->read_by_admin_at?->toIso8601String(),
            'created_at' => $m->created_at->toIso8601String(),
            'admin' => $m->sender_is_admin && $m->adminUser
                ? ['id' => $m->adminUser->id, 'name' => $m->adminUser->name]
                : null,
        ];
    }
}
