<?php

namespace App\Services;

use App\Models\FcmDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FcmDeviceRegistrationService
{
    /**
     * Upserts one row per (user_id, FCM token). Idempotent for the same token.
     *
     * @param  'android'|'ios'|'other'  $platform  Already normalized by the controller.
     */
    public function upsertForUser(User $user, string $fcmToken, string $platform, ?string $appVersion): FcmDeviceRegistration
    {
        $hash = hash('sha256', $fcmToken);

        $registration = DB::transaction(function () use ($user, $fcmToken, $hash, $platform, $appVersion): FcmDeviceRegistration {
            return FcmDeviceRegistration::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'fcm_token_hash' => $hash,
                ],
                [
                    'fcm_token' => $fcmToken,
                    'platform' => $platform,
                    'app_version' => $appVersion,
                    'last_seen_at' => now(),
                ],
            );
        });

        Log::info('fcm_device_registration_upsert', [
            'user_id' => $user->id,
            'platform' => $platform,
            'fcm_token_suffix' => strlen($fcmToken) > 8 ? substr($fcmToken, -8) : '***',
            'fcm_token_hash_prefix' => substr($hash, 0, 8).'…',
        ]);

        return $registration->fresh();
    }
}
