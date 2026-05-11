<?php

use App\Services\FcmDeviceRegistrationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push notification device registrations (FCM).
 *
 * Uniqueness is (user_id, fcm_token_hash) because a full FCM token can exceed
 * MySQL's maximum key length when indexed as VARCHAR alongside user_id.
 * {@see FcmDeviceRegistrationService} stores sha256(token) in fcm_token_hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_device_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('fcm_token_hash', 64);
            $table->text('fcm_token');
            $table->string('platform', 32);
            $table->string('app_version', 128)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fcm_token_hash']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_device_registrations');
    }
};
