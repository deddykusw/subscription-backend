<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Speeds up lookup by attendance_token (voucher redeem / history, etc.).
     *
     * MySQL/MariaDB require a prefix length on TEXT/BLOB index keys; other drivers
     * get a normal column index.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE attendance_profiles ADD INDEX attendance_profiles_attendance_token_index (attendance_token(191))'
            );

            return;
        }

        Schema::table('attendance_profiles', function (Blueprint $table) {
            $table->index('attendance_token');
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE attendance_profiles DROP INDEX attendance_profiles_attendance_token_index'
            );

            return;
        }

        Schema::table('attendance_profiles', function (Blueprint $table) {
            $table->dropIndex(['attendance_token']);
        });
    }
};
