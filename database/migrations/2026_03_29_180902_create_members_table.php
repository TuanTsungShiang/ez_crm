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
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->comment('對外公開唯一識別碼');
            $table->foreignId('member_group_id')->nullable()->constrained('member_groups')->nullOnDelete()->comment('所屬分群');
            $table->string('name', 100)->comment('真實姓名');
            $table->string('nickname', 100)->nullable()->comment('暱稱');
            $table->string('email', 191)->unique()->nullable()->comment('電子信箱');
            $table->string('phone', 20)->unique()->nullable()->comment('手機號碼');
            $table->string('password')->nullable()->comment('密碼，第三方登入可為空');
            $table->timestamp('email_verified_at')->nullable()->comment('Email 驗證時間');
            $table->timestamp('phone_verified_at')->nullable()->comment('手機驗證時間');
            $table->tinyInteger('status')->default(2)->comment('1=正常 0=停用 2=待驗證');
            $table->timestamp('last_login_at')->nullable()->comment('最後登入時間');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
