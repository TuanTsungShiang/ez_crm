<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit log of every state transition on an order.
     *
     * Mirrors the design of point_transactions: never UPDATE, only INSERT;
     * one row per transition with from/to status, the actor, the reason,
     * and a JSON `meta` for transition-specific context (ECPay trade_no
     * for paid; cron run_at for timeout-cancel; admin reason for refund).
     *
     * `from_status` is nullable because the very first row (creation)
     * has no prior state — to_status='pending' from null.
     *
     * No updated_at: append-only.
     */
    public function up(): void
    {
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->enum('from_status', [
                'pending', 'paid', 'shipped', 'completed',
                'cancelled', 'partial_refunded', 'refunded',
            ])->nullable();
            $table->enum('to_status', [
                'pending', 'paid', 'shipped', 'completed',
                'cancelled', 'partial_refunded', 'refunded',
            ]);

            $table->string('reason', 500)->nullable();

            $table->foreignId('actor_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->enum('actor_type', ['member', 'user', 'system'])
                ->default('user');

            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
