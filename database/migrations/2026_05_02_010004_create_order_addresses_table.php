<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shipping / billing address snapshot, frozen at order time.
     *
     * Why a separate table rather than columns on `orders` (per §3.4):
     * Member.profile may change (move house) but the order's ship-to
     * address must stay exactly what it was when the order was placed.
     * Boundary-state-must-be-frozen, the same pattern as order_items.
     *
     * `unique(order_id, type)` enforces "exactly one shipping + at most
     * one billing per order" — duplicates would silently corrupt totals.
     */
    public function up(): void
    {
        Schema::create('order_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['shipping', 'billing']);

            $table->string('recipient_name', 100);
            $table->string('phone', 32);
            $table->string('country', 8)->default('TW');
            $table->string('postal_code', 16);
            $table->string('city', 64);
            $table->string('district', 64);
            $table->string('address_line', 200);
            $table->string('address_line2', 200)->nullable();

            $table->timestamps();

            $table->unique(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_addresses');
    }
};
