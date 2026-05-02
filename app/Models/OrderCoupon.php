<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model for the order_coupons junction.
 *
 * Custom Pivot is used rather than a plain pivot row so that
 * discount_applied + apply_order can be accessed as integer-cast
 * attributes (rather than raw strings) when iterating
 * Order->coupons->each fn ($c) => $c->pivot->discount_applied.
 *
 * @property int $discount_applied
 * @property int $apply_order
 */
class OrderCoupon extends Pivot
{
    protected $table = 'order_coupons';

    public $incrementing = true;

    protected $casts = [
        'discount_applied' => 'integer',
        'apply_order'      => 'integer',
    ];
}
