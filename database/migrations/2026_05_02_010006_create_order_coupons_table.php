<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Many-to-many junction: which coupons applied to which order, and
     * exactly how much each one discounted at apply time.
     *
     * Per ORDER_INTEGRATION_PLAN §3.6 / §5b decision: ez_crm supports
     * stacking multiple coupons per order (real-commerce convention),
     * with apply order driven by the coupon's category + priority.
     *
     * `discount_applied` is a snapshot — coupon rules can change later,
     * but the order's recorded discount per coupon must stay frozen.
     * `apply_order` records the resolved sequence at create time so the
     * order's final total can be re-explained without re-running the
     * priority engine.
     *
     * `restrictOnDelete` on coupon_id is intentional: a coupon used in
     * an order must never be hard-deleted; cancellation goes through
     * CouponService which sets coupon.status='cancelled' and writes
     * an audit row, not by physical delete.
     */
    public function up(): void
    {
        Schema::create('order_coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();

            $table->bigInteger('discount_applied');
            $table->unsignedInteger('apply_order')
                ->comment('0-based,反映 priority engine 解析後的套用順序');

            $table->timestamps();

            $table->unique(['order_id', 'coupon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_coupons');
    }
};
