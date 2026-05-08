<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->foreignId('referred_user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->foreignId('referral_id')
                  ->constrained('referrals')
                  ->cascadeOnDelete();
            $table->foreignId('subscription_id')
                  ->constrained('subscriptions')
                  ->cascadeOnDelete();
            $table->foreignId('payment_order_id')
                  ->constrained('payment_orders')
                  ->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('commission_percentage', 5, 2);
            $table->enum('status', ['pending', 'credited', 'paid_out', 'cancelled'])
                  ->default('pending');
            $table->timestamp('credited_at')->nullable();
            $table->timestamp('paid_out_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('referrer_user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
