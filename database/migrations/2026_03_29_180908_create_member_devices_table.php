<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('member_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete()->comment('關聯會員');
            $table->string('platform', 20)->comment('平台：ios / android / web');
            $table->string('device_token', 512)->comment('推播 Token');
            $table->boolean('is_active')->default(true)->comment('是否啟用');
            $table->timestamp('last_used_at')->nullable()->comment('最後使用時間');
            $table->timestamps();

            $table->unique(['member_id', 'device_token']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_devices');
    }
};
