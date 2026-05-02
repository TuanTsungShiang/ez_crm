<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $order_id
 * @property string $product_sku
 * @property string $product_name
 * @property int    $unit_price
 * @property int    $quantity
 * @property int    $subtotal
 * @property array|null $product_meta
 */
class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_sku', 'product_name',
        'unit_price', 'quantity', 'subtotal', 'product_meta',
    ];

    protected $casts = [
        'unit_price'   => 'integer',
        'quantity'     => 'integer',
        'subtotal'     => 'integer',
        'product_meta' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
