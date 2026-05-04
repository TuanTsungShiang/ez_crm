<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2.3 hotfix: stop MySQL from silently mutating callback_time.
     *
     * The original migration declared:
     *   $table->timestamp('callback_time')
     *
     * Because callback_time was the first TIMESTAMP column without an
     * explicit default and the legacy MySQL `explicit_defaults_for_timestamp`
     * is OFF on this server, MySQL implicitly added:
     *
     *   DEFAULT CURRENT_TIMESTAMP
     *   ON UPDATE CURRENT_TIMESTAMP
     *
     * The "ON UPDATE" part broke the replay-defense flow:
     *   1. INSERT row with callback_time = ECPay PaymentDate ('2026-05-03 10:00:00')
     *   2. handleCallback runs $cb->update(['status' => 'verified'])
     *      → MySQL silently rewrites callback_time to NOW()
     *   3. handleCallback runs $cb->update(['status' => 'processed'])
     *      → callback_time auto-rewritten again
     *
     * Effect: the unique(provider, trade_no, callback_time) backstop never
     * fires on a true ECPay retry — by the time the duplicate POST arrives,
     * the existing row's callback_time has drifted to a fresh NOW(), so the
     * 2nd INSERT sees no conflict and succeeds.
     *
     * Fix: use DATETIME instead of TIMESTAMP. DATETIME never receives the
     * implicit DEFAULT/ON-UPDATE CURRENT_TIMESTAMP behavior. The semantic
     * (UTC-stored ECPay PaymentDate snapshot) is unchanged.
     *
     * Caught by EcpayWebhookTest::test_replay_same_timestamp_db_unique_prevents_second_insert.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE payment_callbacks MODIFY callback_time DATETIME NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_callbacks MODIFY callback_time TIMESTAMP NOT NULL');
    }
};
