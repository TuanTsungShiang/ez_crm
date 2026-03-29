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
        Schema::create('member_login_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete()->comment('關聯會員');
            $table->string('ip_address', 45)->nullable()->comment('登入 IP，支援 IPv6');
            $table->string('user_agent', 512)->nullable()->comment('瀏覽器裝置資訊');
            $table->string('platform', 50)->nullable()->comment('平台：web / ios / android');
            $table->string('login_method', 30)->comment('登入方式：email / phone / google / line ...');
            $table->boolean('status')->default(true)->comment('1=成功 0=失敗');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_login_histories');
    }
};
