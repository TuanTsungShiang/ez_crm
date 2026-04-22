<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('給人看的識別名稱');
            $table->string('url', 500)->comment('接收端 URL');
            $table->json('events')->comment('訂閱的事件類型清單');

            // 現用 secret + 舊 secret 過渡期（secret rotation 平滑過渡）
            $table->string('secret', 64)->comment('HMAC 簽章密鑰');
            $table->string('previous_secret', 64)->nullable()
                ->comment('Rotation 期間仍可驗證的舊 secret');
            $table->timestamp('previous_secret_expires_at')->nullable()
                ->comment('舊 secret 何時徹底失效');

            // 人為開關 vs 自動斷路
            $table->boolean('is_active')->default(true)->comment('admin 手動啟停');
            $table->boolean('is_circuit_broken')->default(false)
                ->comment('連續失敗自動斷路,需 admin 手動解除');
            $table->unsignedSmallInteger('consecutive_failure_count')->default(0)
                ->comment('連續失敗次數,成功一次歸零');

            $table->unsignedTinyInteger('max_retries')->default(5);
            $table->unsignedTinyInteger('timeout_seconds')->default(10);

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'is_circuit_broken']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
