<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-item line snapshot for an order.
     *
     * No `product_id` FK by design (per ORDER_INTEGRATION_PLAN §3.3) —
     * ez_crm intentionally does NOT manage a Product table. Item identity
     * is the snapshotted (sku, name, unit_price, quantity, product_meta)
     * tuple, frozen at order create time so that later product price /
     * name changes never mutate historical orders.
     *
     * Same reasoning as point_transactions.balance_after: the order row
     * must reproduce its exact total from its own line items without
     * joining a live products table.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('product_sku', 64);
            $table->string('product_name', 200);
            $table->bigInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->bigInteger('subtotal')
                ->comment('unit_price × quantity,寫入時計算,不依賴 application 重算');

            $table->json('product_meta')->nullable()
                ->comment('規格 / 圖片 URL / 商品分類等延伸快照');

            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
