<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Unique display/login handle from the attendance server.
            // Nullable for backward-compatibility with existing rows.
            $table->string('username')->nullable()->unique()->after('email');

            // Primary key of this user on the attendance (presensi) server.
            // Unsigned to match typical auto-increment IDs; no FK since the
            // attendance DB is external.
            $table->unsignedInteger('attendance_server_id')->nullable()->after('username');

            // Tracks when the bearer token was last successfully validated
            // against the attendance server, enabling stale-token detection.
            $table->timestamp('last_token_validation_at')->nullable()->after('attendance_server_id');

            $table->index('username', 'idx_users_username');
            $table->index('attendance_server_id', 'idx_users_attendance_server_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_attendance_server_id');
            $table->dropIndex('idx_users_username');

            $table->dropColumn([
                'username',
                'attendance_server_id',
                'last_token_validation_at',
            ]);
        });
    }
};
