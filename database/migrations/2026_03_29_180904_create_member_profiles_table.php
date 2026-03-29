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
        Schema::create('member_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained('members')->cascadeOnDelete()->comment('關聯會員（一對一）');
            $table->string('avatar')->nullable()->comment('頭像圖片路徑');
            $table->tinyInteger('gender')->nullable()->comment('1=男 2=女 0=不提供');
            $table->date('birthday')->nullable()->comment('生日');
            $table->text('bio')->nullable()->comment('個人簡介');
            $table->string('language', 10)->nullable()->comment('偏好語言，如 zh-TW');
            $table->string('timezone', 50)->nullable()->comment('時區，如 Asia/Taipei');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_profiles');
    }
};
