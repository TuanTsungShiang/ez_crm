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
        Schema::create('member_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete()->comment('關聯會員');
            $table->string('type', 20)->comment('驗證類型：email / phone / password_reset');
            $table->string('token', 10)->comment('OTP 驗證碼');
            $table->timestamp('expires_at')->comment('到期時間');
            $table->timestamp('verified_at')->nullable()->comment('驗證成功時間');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_verifications');
    }
};
