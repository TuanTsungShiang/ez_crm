<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inbound payment-provider webhook ledger + replay defense.
     *
     * Every ECPay (and future provider) callback is logged here BEFORE
     * we trust its content. The full raw body lives in `raw_payload`
     * for forensic / audit / dispute purposes — even if the signature
     * verification fails, we keep the row so abuse attempts are visible.
     *
     * Replay defense is layered (per §9b decision = C 雙保險):
     *   1. App-level: ECPayService.handleCallback checks if a row with
     *      the same provider+trade_no already has status='processed'
     *      and short-circuits with status='duplicate'.
     *   2. DB-level: the unique(provider, trade_no, callback_time)
     *      constraint catches concurrent callbacks that both pass the
     *      app check — only one INSERT wins, the other throws 23000
     *      and is treated as duplicate.
     *
     * `order_id` is nullable because a callback may arrive for an order
     * we cannot resolve (race / data corruption / replay attack against
     * a deleted order). The row is kept with status='failed' for alert.
     */
    public function up(): void
    {
        Schema::create('payment_callbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('provider', 32)->default('ecpay');
            $table->string('trade_no', 64);
            $table->string('rtn_code', 8);
            $table->string('rtn_msg', 200)->nullable();
            $table->bigInteger('amount')->nullable();
            $table->timestamp('callback_time')
                ->comment('ECPay PaymentDate,replay 去重的 time component');

            $table->enum('status', [
                'received', 'verified', 'processed', 'failed', 'duplicate',
            ])->default('received');

            $table->json('raw_payload');
            $table->timestamps();

            // Replay defense — DB UNIQUE backstop
            $table->unique(['provider', 'trade_no', 'callback_time']);
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_callbacks');
    }
};
