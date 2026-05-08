<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores the attendance server profile for each user.
     *
     * This table is the source of truth for:
     *   - Whether the user is an active employee (is_active)
     *   - Employee metadata (jabatan, unit kerja, tipe)
     *   - The bearer token used to call the attendance server on the user's behalf
     *
     * The token is intentionally stored in plain text because it must be sent
     * as a Bearer header to the attendance server; it is not a password and
     * has no value outside the attendance server's own API.
     */
    public function up(): void
    {
        Schema::create('attendance_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->unique()
                  ->constrained('users')
                  ->cascadeOnDelete();

            // ── Credentials (stored for automatic token refresh) ───────────────
            // The username and password are used to re-login to the attendance
            // server when the token expires, so the user does not need to
            // manually re-authenticate from the Android app.
            //
            $table->string('attendance_username')->nullable();
            $table->string('attendance_password')->nullable();

            // ── Token ──────────────────────────────────────────────────────────
            // Bearer token issued by the attendance server after a successful login.
            // Used by the backend to call /api/user/profile and other attendance APIs.
            $table->text('attendance_token');

            // When this token was last obtained / refreshed.
            $table->timestamp('token_obtained_at');

            // ── Identity fields (mirrored from attendance server) ──────────────
            $table->unsignedBigInteger('attendance_user_id')
                  ->comment('attendance server user.id');

            $table->unsignedBigInteger('id_peg')->nullable()
                  ->comment('Employee ID (id_peg)');

            // ── Access control ─────────────────────────────────────────────────
            // Primary gate: is_active from the attendance server.
            // true  = active employee → allowed to use the app
            // false = inactive / resigned → blocked regardless of subscription
            $table->boolean('is_active')->default(false);

            // ── Employee metadata ──────────────────────────────────────────────
            $table->string('tipe')->nullable()
                  ->comment('Employee type: ASN, PPPK, etc.');

            $table->string('jabatan')->nullable()
                  ->comment('Job title / position');

            $table->unsignedBigInteger('kode_unit_kerja')->nullable();
            $table->unsignedBigInteger('kode_uptd')->nullable();

            $table->text('avatar_url')->nullable();

            $table->timestamp('last_integrity_check_at')->nullable()
                  ->comment('Mirrored from attendance server profile');

            $table->timestamps();

            $table->index('attendance_user_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_profiles');
    }
};
