<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();

            // ── Code ──────────────────────────────────────────────────────────
            $table->string('code')->unique()
                  ->comment('Uppercase alphanumeric code entered by the user');

            // ── What the voucher grants ────────────────────────────────────────
            $table->unsignedInteger('duration_days')
                  ->comment('Number of subscription days granted on redemption');

            // Optional: tie the voucher to a specific plan.
            // When null, the cheapest active plan is used (same as trial logic).
            $table->foreignId('plan_id')
                  ->nullable()
                  ->constrained('subscription_plans')
                  ->nullOnDelete();

            // ── Usage limits ──────────────────────────────────────────────────
            $table->unsignedInteger('max_uses')->nullable()
                  ->comment('NULL = unlimited uses');

            $table->unsignedInteger('used_count')->default(0);

            // ── Validity window ───────────────────────────────────────────────
            $table->boolean('is_active')->default(true);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();

            // ── Metadata ──────────────────────────────────────────────────────
            $table->text('notes')->nullable()
                  ->comment('Internal admin notes — not shown to users');

            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            $table->index(['is_active', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
