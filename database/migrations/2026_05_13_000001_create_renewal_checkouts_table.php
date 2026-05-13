<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_checkouts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('subscription_plan_id')
                ->constrained('subscription_plans');

            $table->string('period', 16);
            $table->string('status', 32);

            $table->decimal('amount', 12, 2);
            $table->string('currency', 8);

            $table->string('proof_disk')->nullable();
            $table->string('proof_path')->nullable();
            $table->timestamp('payment_proof_submitted_at')->nullable();

            $table->timestamp('upload_deadline_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_checkouts');
    }
};
