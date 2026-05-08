<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->foreignId('referred_user_id')
                  ->unique()  // each user can only be referred once
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->string('referral_code');
            $table->enum('status', ['pending', 'completed', 'cancelled'])
                  ->default('pending');
            $table->timestamp('referred_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('referrer_user_id');
            $table->index('referral_code');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
