<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();

            $table->string('code', 32)->unique()
                ->comment('人類可讀代碼，例：EZCRM-A3F7-K9P2');

            $table->foreignId('batch_id')->constrained('coupon_batches')->cascadeOnDelete();

            // null = 通用券（任何會員可用）；有值 = 指定會員專屬
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();

            $table->enum('status', ['created', 'redeemed', 'cancelled', 'expired'])
                ->default('created');

            // 核銷資訊
            $table->foreignId('redeemed_by')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();

            // 取消資訊
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->json('meta')->nullable()->comment('額外 context，例：核銷訂單 ID');

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
