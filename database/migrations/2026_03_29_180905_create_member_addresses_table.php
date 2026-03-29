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
        Schema::create('member_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete()->comment('關聯會員');
            $table->string('label', 50)->nullable()->comment('地址標籤，如：家、公司');
            $table->string('recipient_name', 100)->comment('收件人姓名');
            $table->string('recipient_phone', 20)->comment('收件人電話');
            $table->string('country', 10)->default('TW')->comment('國家代碼');
            $table->string('zip_code', 10)->nullable()->comment('郵遞區號');
            $table->string('city', 50)->comment('縣市');
            $table->string('district', 50)->nullable()->comment('區域');
            $table->string('address')->comment('詳細地址');
            $table->boolean('is_default')->default(false)->comment('是否為預設地址');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_addresses');
    }
};
