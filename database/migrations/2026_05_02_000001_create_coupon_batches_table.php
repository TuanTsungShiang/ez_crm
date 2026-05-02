<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();

            $table->enum('type', ['discount_amount', 'discount_percent', 'points'])
                ->comment('discount_amount=NT折抵 / discount_percent=折扣% / points=兌換點數');

            $table->unsignedInteger('value')
                ->comment('discount_amount=NT整數 / discount_percent=百分比 / points=點數整數');

            $table->unsignedInteger('quantity')->comment('此批次產生的券數');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_batches');
    }
};
