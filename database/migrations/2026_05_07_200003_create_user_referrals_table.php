<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->unique()
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->string('referral_code')->unique()->index();
            $table->unsignedInteger('total_referrals')->default(0);
            $table->decimal('total_earnings', 15, 2)->default(0);
            $table->decimal('total_paid_out', 15, 2)->default(0);
            $table->decimal('pending_earnings', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_referrals');
    }
};
