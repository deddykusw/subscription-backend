<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('renewal_checkouts', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable()->after('payment_proof_submitted_at');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->string('admin_notes', 1000)->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('renewal_checkouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['reviewed_at', 'admin_notes']);
        });
    }
};
