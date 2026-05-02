<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-row configuration for the orders domain.
     *
     * Holds settings that the admin may tune from the back office:
     * order_no prefix, points earn rate, pending-timeout window, and the
     * minimum chargeable amount after discounts. We keep them on a single
     * dedicated table (rather than a generic key-value app_settings) per
     * Phase 2.3 review decision §2a/§2c — strict YAGNI, single global
     * prefix is sufficient until multi-frontend lands.
     *
     * See ORDER_INTEGRATION_PLAN §3.1 / §10.1 for the multi-prefix
     * upgrade path (estimated 1 day, fully additive).
     */
    public function up(): void
    {
        Schema::create('order_settings', function (Blueprint $table) {
            $table->id();
            $table->string('order_no_prefix', 16)->default('EZ');
            $table->decimal('points_rate', 5, 4)->default(0.0100)
                ->comment('1% 預設,4 位小數精度;completed 時用 paid_amount × rate floor 加點');
            $table->unsignedInteger('pending_timeout_minutes')->default(30)
                ->comment('Cron 每 5 分鐘掃 pending 訂單超過此分鐘數即 cancel');
            $table->unsignedInteger('min_charge_amount')->default(1)
                ->comment('多券疊加折抵後最低金額(防 0 元下單)');
            $table->timestamps();
        });

        // Seed the singleton row so OrderService can always read it.
        DB::table('order_settings')->insert([
            'order_no_prefix'         => 'EZ',
            'points_rate'             => 0.0100,
            'pending_timeout_minutes' => 30,
            'min_charge_amount'       => 1,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_settings');
    }
};
