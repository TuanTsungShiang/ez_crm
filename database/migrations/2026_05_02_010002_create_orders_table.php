<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Order header — every customer purchase produces exactly one row here.
     *
     * Design decisions (per ORDER_INTEGRATION_PLAN §3.2):
     *   - Internal `id` PK for joins / FKs; external `order_no` UNIQUE for
     *     receipts / customer support / human reference.
     *   - All money columns are `bigInteger` (TWD = no decimals), aligned
     *     with point_transactions.amount.
     *   - Status timestamps (paid_at / shipped_at / etc.) live in their
     *     own columns rather than being derived from order_status_histories
     *     — query performance + BI ergonomics.
     *   - `idempotency_key` UNIQUE blocks double-submit replays at the DB
     *     level; OrderService catches QueryException 23000 to handle the
     *     two-concurrent-requests-same-key TOCTOU race (same defense as
     *     PointService).
     *   - `ecpay_trade_no` UNIQUE pairs with `payment_callbacks` for
     *     replay defense (§3.9) and ensures one ECPay trade ↔ one order.
     *
     * Direct writes to this table are forbidden — every state change
     * must route through OrderService so status_histories and the
     * cached money totals stay in sync inside one DB transaction.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 32)->unique()
                ->comment('{PREFIX}-{YYYYMMDD}-{NNNN},對外 surface');

            $table->foreignId('member_id')->constrained()->restrictOnDelete();

            $table->enum('status', [
                'pending', 'paid', 'shipped', 'completed',
                'cancelled', 'partial_refunded', 'refunded',
            ])->default('pending');

            // Money snapshot — frozen at creation, mutated only by OrderService.
            $table->bigInteger('subtotal')
                ->comment('商品小計(折扣前)= sum(order_items.subtotal)');
            $table->bigInteger('discount_total')->default(0)
                ->comment('所有 order_coupons.discount_applied 加總');
            $table->bigInteger('paid_amount')
                ->comment('實付金額 = subtotal - discount_total');
            $table->bigInteger('refund_amount')->default(0)
                ->comment('累計已退款(partial 多次累加,達 paid_amount 自動轉 refunded)');

            // Points snapshot — populated at completed (T5/T8).
            $table->bigInteger('points_earned')->default(0);
            $table->bigInteger('points_refunded')->default(0);

            // Idempotency
            $table->string('idempotency_key', 64)->unique();

            // Payment
            $table->enum('payment_method', ['ecpay', 'offline'])->nullable();
            $table->string('ecpay_trade_no', 32)->nullable()->unique()
                ->comment('ECPay MerchantTradeNo,paid 時寫入,replay 防禦的對外 ID');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Audit — who/what triggered creation
            $table->foreignId('created_by_actor_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->enum('created_by_actor_type', ['member', 'user', 'system'])
                ->default('member');

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
