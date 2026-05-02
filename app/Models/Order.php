<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Order header — one row per customer purchase.
 *
 * NEVER write to this model's status / money columns directly. Every
 * change must route through OrderService so order_status_histories is
 * recorded and money totals stay consistent inside one DB transaction.
 *
 * @property int         $id
 * @property string      $order_no
 * @property int         $member_id
 * @property string      $status
 * @property int         $subtotal
 * @property int         $discount_total
 * @property int         $paid_amount
 * @property int         $refund_amount
 * @property int         $points_earned
 * @property int         $points_refunded
 * @property string      $idempotency_key
 * @property string|null $payment_method
 * @property string|null $ecpay_trade_no
 * @property \Carbon\Carbon|null $paid_at
 * @property \Carbon\Carbon|null $shipped_at
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon|null $cancelled_at
 * @property int|null    $created_by_actor_id
 * @property string      $created_by_actor_type
 * @property array|null  $meta
 */
class Order extends Model
{
    public const STATUS_PENDING          = 'pending';
    public const STATUS_PAID             = 'paid';
    public const STATUS_SHIPPED          = 'shipped';
    public const STATUS_COMPLETED        = 'completed';
    public const STATUS_CANCELLED        = 'cancelled';
    public const STATUS_PARTIAL_REFUNDED = 'partial_refunded';
    public const STATUS_REFUNDED         = 'refunded';

    public const PAYMENT_METHOD_ECPAY   = 'ecpay';
    public const PAYMENT_METHOD_OFFLINE = 'offline';

    public const ACTOR_MEMBER = 'member';
    public const ACTOR_USER   = 'user';
    public const ACTOR_SYSTEM = 'system';

    protected $fillable = [
        'order_no', 'member_id', 'status',
        'subtotal', 'discount_total', 'paid_amount', 'refund_amount',
        'points_earned', 'points_refunded',
        'idempotency_key',
        'payment_method', 'ecpay_trade_no',
        'paid_at', 'shipped_at', 'completed_at', 'cancelled_at',
        'created_by_actor_id', 'created_by_actor_type',
        'meta',
    ];

    protected $casts = [
        'subtotal'        => 'integer',
        'discount_total'  => 'integer',
        'paid_amount'     => 'integer',
        'refund_amount'   => 'integer',
        'points_earned'   => 'integer',
        'points_refunded' => 'integer',
        'paid_at'         => 'datetime',
        'shipped_at'      => 'datetime',
        'completed_at'    => 'datetime',
        'cancelled_at'    => 'datetime',
        'meta'            => 'array',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    public function shippingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', 'shipping');
    }

    public function billingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', 'billing');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class, 'order_coupons')
            ->using(OrderCoupon::class)
            ->withPivot(['discount_applied', 'apply_order'])
            ->withTimestamps()
            ->orderByPivot('apply_order');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function paymentCallbacks(): HasMany
    {
        return $this->hasMany(PaymentCallback::class);
    }

    /**
     * The User (admin) who created this order, if any. Member-created
     * orders have created_by_actor_type='member' and this returns null.
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_actor_id');
    }
}
