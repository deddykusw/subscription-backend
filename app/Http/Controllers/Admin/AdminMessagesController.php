<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminMessagesController extends Controller
{
    public function __construct(private readonly MessageService $messageService) {}

    public function index(Request $request): Response
    {
        $paginator = $this->messageService->conversationSummariesForAdmin(20);

        $userIds = collect($paginator->items())->pluck('user_id')->unique()->filter()->all();
        $users    = User::query()->whereIn('id', $userIds)->get(['id', 'name', 'email', 'username'])->keyBy('id');

        $paginator->setCollection(
            collect($paginator->items())->map(function ($row) use ($users) {
                $uid = (int) $row->user_id;
                $u   = $users->get($uid);

                return [
                    'user_id'            => $uid,
                    'last_message_at'      => $row->last_message_at,
                    'unread_for_admin'     => (int) $row->unread_for_admin,
                    'user'               => $u ? [
                        'id'       => $u->id,
                        'name'     => $u->name,
                        'email'    => $u->email,
                        'username' => $u->username,
                    ] : null,
                ];
            })
        );

        return Inertia::render('Admin/Messages/Index', [
            'threads' => $paginator,
        ]);
    }

    public function show(User $user): Response|RedirectResponse
    {
        if ($user->is_admin) {
            return redirect()->route('admin.messages.index')->with('error', 'Tidak ada percakapan untuk akun admin.');
        }

        $this->messageService->markReadByAdmin($user->id);

        $messages = Message::query()
            ->where('user_id', $user->id)
            ->with(['adminUser:id,name'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Message $m) => [
                'id'               => $m->id,
                'body'             => $m->body,
                'sender_is_admin'  => $m->sender_is_admin,
                'read_by_user_at'  => $m->read_by_user_at?->toIso8601String(),
                'read_by_admin_at' => $m->read_by_admin_at?->toIso8601String(),
                'created_at'       => $m->created_at->toIso8601String(),
                'admin'            => $m->sender_is_admin && $m->adminUser
                    ? ['id' => $m->adminUser->id, 'name' => $m->adminUser->name]
                    : null,
            ]);

        return Inertia::render('Admin/Messages/Show', [
            'threadUser' => [
                'id'       => $user->id,
                'name'     => $user->name,
                'email'    => $user->email,
                'username' => $user->username,
            ],
            'messages' => $messages,
        ]);
    }

    public function reply(Request $request, User $user): RedirectResponse
    {
        if ($user->is_admin) {
            return redirect()->route('admin.messages.index')->with('error', 'Tidak dapat membalas akun admin.');
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:10000'],
        ]);

        try {
            $this->messageService->sendFromAdmin(
                $request->user(),
                $user->id,
                trim($validated['message']),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return redirect()->route('admin.messages.show', $user)->with('success', 'Balasan terkirim.');
    }
}
