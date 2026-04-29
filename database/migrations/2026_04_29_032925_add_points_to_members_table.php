<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the points balance cache column on members.
     *
     * Source of truth = SUM(point_transactions.amount). This column is a
     * cached materialized total, kept atomic with each transaction insert
     * (see PointService::adjust). If it ever drifts, run
     * `php artisan points:rebuild {member_uuid?}` (TODO: Phase 2.1 Day 4).
     *
     * See POINTS_INTEGRATION_PLAN.md §3.1.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->bigInteger('points')
                ->default(0)
                ->after('status')
                ->comment('會員當前點數餘額(快取自 point_transactions 累計)');
            $table->index('points');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex(['points']);
            $table->dropColumn('points');
        });
    }
};
