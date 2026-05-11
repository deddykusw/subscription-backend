<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * User ↔ admin messaging only (no user-to-user). `user_id` is always the subscriber.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('sender_is_admin')->default(false);
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('read_by_user_at')->nullable();
            $table->timestamp('read_by_admin_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['sender_is_admin', 'read_by_admin_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
