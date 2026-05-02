<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2.3 retrofit on coupons for multi-coupon stacking.
     *
     * `category` distinguishes coupons that may stack ('discount' +
     * 'threshold' + 'shipping' all at once) from those that conflict
     * (two 'discount' coupons on one order is not allowed) — see §5d.
     *
     * `priority` controls the apply order when stacking multiple
     * coupons; lower number first. Recorded into order_coupons.apply_order
     * at create time so the resolved sequence is reproducible without
     * re-running the priority engine.
     *
     * Both columns default to safe values so existing Phase 2.2 coupons
     * keep working without backfill: every legacy coupon is treated as
     * a 'discount' category at priority 100.
     */
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->enum('category', ['discount', 'threshold', 'shipping'])
                ->default('discount')
                ->after('member_id');
            $table->unsignedInteger('priority')
                ->default(100)
                ->after('category')
                ->comment('多券同時套用的順序(小→大),預設 100');

            $table->index(['category', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropIndex(['category', 'priority']);
            $table->dropColumn(['category', 'priority']);
        });
    }
};
