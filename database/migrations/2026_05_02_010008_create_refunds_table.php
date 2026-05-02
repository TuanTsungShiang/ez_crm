<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-event refund ledger.
     *
     * Phase 2.3 supports partial refunds (per §3c): one row per refund
     * event, accumulated against orders.refund_amount. When the running
     * total reaches paid_amount, OrderService flips the order to the
     * `refunded` terminal state.
     *
     * `point_transaction_id` links to the reverse PointService::adjust
     * row that gave back the proportional points — keeping the refund
     * traceable across modules without a separate sync mechanism.
     *
     * `ecpay_refund_no` / `ecpay_status` are reserved nullable columns
     * for ECPay L2 (refund API integration). Phase 2.3 (L1) leaves them
     * NULL — admins do refunds manually through the ECPay dashboard
     * after our row is recorded; L2 wires up the API call.
     *
     * `restrictOnDelete` on order_id: refund rows are immutable history.
     */
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();

            $table->bigInteger('amount');
            $table->enum('type', ['partial', 'full'])->default('partial');
            $table->string('reason', 500);

            $table->foreignId('processed_by')->constrained('users');
            $table->timestamp('processed_at');

            // ECPay L2 reserved
            $table->string('ecpay_refund_no', 64)->nullable();
            $table->enum('ecpay_status', ['pending', 'success', 'failed'])->nullable();

            // Cross-module link to the reverse PointTransaction
            $table->foreignId('point_transaction_id')->nullable()
                ->constrained('point_transactions')->nullOnDelete();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
