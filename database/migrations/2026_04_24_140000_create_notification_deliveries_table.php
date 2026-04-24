<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 通知發送記錄(跨 channel:sms / line / fcm / email / ...).
     * 不叫 notifications 以避開 Laravel DatabaseNotification 的預設 table。
     */
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32)->comment('sms / line / fcm / email / webhook');
            $table->string('driver', 32)->nullable()->comment('sms:mitake/log/null, email:smtp, line:...');
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to_address')->comment('phone / email / line user id / fcm token');
            $table->text('content');
            $table->string('purpose', 32)->comment('otp_login / otp_register / marketing / transaction / alert');
            $table->enum('status', ['queued', 'sent', 'delivered', 'failed', 'bounced'])->default('queued');
            $table->string('provider_message_id')->nullable();
            $table->decimal('credits_used', 8, 2)->nullable();
            $table->text('error_message')->nullable();
            $table->json('fallback_attempts')->nullable()
                ->comment('Phase 8.3+:多通道 fallback 時記錄每一次嘗試結果');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'created_at']);
            $table->index(['channel', 'status']);
            $table->index(['purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
