<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit log + idempotency control for all points changes.
     *
     * Every change to members.points goes through PointService::adjust which
     * inserts one row here inside a DB transaction with lockForUpdate on the
     * member row. The unique idempotency_key constraint enforces "same logical
     * operation, same outcome, no double-execute" at the database level —
     * application code does NOT need to defend against replays.
     *
     * See POINTS_INTEGRATION_PLAN.md §3.2.
     */
    public function up(): void
    {
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->bigInteger('amount')
                ->comment('正:加點 / 負:扣點。單筆 transaction 不可為 0');

            $table->bigInteger('balance_after')
                ->comment('交易完成時的餘額快照,稽核用 — 即使 members.points 被改也能還原歷史');

            $table->enum('type', ['earn', 'spend', 'adjust', 'expire', 'refund']);

            $table->string('reason', 200)
                ->comment('人類可讀說明,例:訂單 #123 完成 / 客服補償');

            // Idempotency: client-supplied UUID v4. Unique at DB level so
            // application code can rely on the insert succeeding-or-failing
            // without race conditions.
            $table->string('idempotency_key', 64)->unique();

            // Audit: who executed this transaction
            $table->foreignId('actor_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('actor_type', 32)->default('user')
                ->comment('user / system / order / coupon');

            // Polymorphic source: which entity caused this transaction.
            // Order, Coupon, or null (manual admin adjust).
            $table->nullableMorphs('source');

            // Phase 2.5 reserved: per-row expiry date for FIFO point expiry.
            // Phase 2.1 leaves this null; Phase 2.5 introduces a cron that
            // creates `type=expire` reverse transactions for rows past their
            // expires_at.
            $table->timestamp('expires_at')->nullable()
                ->comment('Phase 2.5 啟用:此筆 earn transaction 何時過期。null = 永不過期');

            // Free-form context (Order snapshot, Coupon code, etc.)
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['member_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
    }
};
