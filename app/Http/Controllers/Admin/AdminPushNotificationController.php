<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FcmDeviceRegistration;
use App\Models\User;
use App\Services\FcmPushService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class AdminPushNotificationController extends Controller
{
    public function __construct(
        private readonly FcmPushService $fcmPushService,
    ) {}

    /**
     * Form: pick users (with at least one FCM registration) and compose title/body.
     */
    public function create(Request $request): Response
    {
        $query = User::query()
            ->withCount('fcmDeviceRegistrations as fcm_devices_count')
            ->whereHas('fcmDeviceRegistrations');

        if ($request->filled('search')) {
            $term = (string) $request->query('search');
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('external_user_id', 'like', "%{$term}%");
            });
        }

        $users = $query->orderBy('name')->paginate(40)->withQueryString();

        return Inertia::render('Admin/Push/Create', [
            'users' => $users,
            'filters' => $request->only(['search']),
            'firebaseConfigured' => $this->fcmPushService->isConfigured(),
        ]);
    }

    /**
     * Sends FCM notification to every registered device for the selected users.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
            'data_json' => ['nullable', 'string', 'max:4000'],
        ]);

        $extraData = [];
        if (! empty($validated['data_json'])) {
            $decoded = json_decode($validated['data_json'], true);
            if (! is_array($decoded)) {
                return back()->withInput()->with('error', 'Field data JSON harus berupa objek JSON valid (key string, value string).');
            }
            foreach ($decoded as $k => $v) {
                if (! is_string($k) || $k === '') {
                    return back()->withInput()->with('error', 'Key data JSON harus berupa string non-kosong.');
                }
                $extraData[$k] = is_scalar($v) ? (string) $v : json_encode($v);
            }
        }

        $tokens = FcmDeviceRegistration::query()
            ->whereIn('user_id', $validated['user_ids'])
            ->pluck('fcm_token')
            ->unique()
            ->values()
            ->all();

        if ($tokens === []) {
            return back()->withInput()->with('error', 'User yang dipilih tidak memiliki perangkat FCM terdaftar.');
        }

        if (! $this->fcmPushService->isConfigured()) {
            return back()->withInput()->with('error', 'Firebase belum dikonfigurasi. Set FIREBASE_CREDENTIALS ke path file service account JSON.');
        }

        Log::info('admin_push_notification_send', [
            'admin_id' => $request->user()?->id,
            'user_ids_count' => count($validated['user_ids']),
            'device_tokens_count' => count($tokens),
            'title_length' => strlen($validated['title']),
        ]);

        $result = $this->fcmPushService->sendToTokens(
            $tokens,
            $validated['title'],
            $validated['body'],
            $extraData,
        );

        $msg = "Push selesai: {$result['success']} perangkat terkirim, {$result['failed']} gagal.";
        if ($result['errors'] !== []) {
            $msg .= ' ('.implode('; ', array_slice($result['errors'], 0, 3)).')';
        }

        if ($result['success'] === 0) {
            return back()->withInput()->with('error', $msg);
        }

        return redirect()->route('admin.push')->with('success', $msg);
    }
}
