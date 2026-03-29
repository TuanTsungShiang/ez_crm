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
        Schema::create('member_sns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete()->comment('關聯會員');
            $table->string('provider', 50)->comment('平台：google / line / facebook / apple');
            $table->string('provider_user_id')->comment('第三方平台用戶 ID');
            $table->text('access_token')->nullable()->comment('Access Token');
            $table->text('refresh_token')->nullable()->comment('Refresh Token');
            $table->timestamp('token_expires_at')->nullable()->comment('Token 到期時間');
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_sns');
    }
};
