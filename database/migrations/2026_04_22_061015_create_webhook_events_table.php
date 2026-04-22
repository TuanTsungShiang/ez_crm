<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id()->comment('即 sequence number,對接收端的 out-of-order 偵測用');
            $table->string('event_type', 100)->comment('例: member.created');
            $table->json('payload')->comment('事件當下的完整快照');
            $table->timestamp('occurred_at', 6)->comment('事件發生時間,微秒精度');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
