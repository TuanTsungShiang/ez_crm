<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Return / exchange request lifecycle.
     *
     * Phase 2.3 scope: schema + Filament read-only list + basic status
     * toggles. The full physical pickup / quality_check / shipping
     * integration is deferred to Phase 2.4 (per §10.6).
     *
     * Why a separate table rather than reusing order_status_histories:
     * a return has its own lifecycle, multiple statuses, an approver,
     * and a return_no that customer support quotes by phone — none of
     * these fit cleanly as a status of the parent order.
     *
     * `restrictOnDelete` on order_id: an order that has returns must
     * never be hard-deleted; the order itself is cancelled / refunded
     * through OrderService, returns are an independent audit trail.
     */
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('return_no', 32)->unique()
                ->comment('R-{YYYYMMDD}-{NNNN},客服可口頭引用');

            $table->enum('type', ['return', 'exchange']);
            $table->enum('status', [
                'requested', 'approved', 'rejected',
                'picked_up', 'received', 'refunded',
            ])->default('requested');

            $table->string('reason', 500);
            $table->bigInteger('refund_amount')->nullable()
                ->comment('approved 後填,driving 對應 refunds row 的 amount');

            $table->foreignId('approved_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};
