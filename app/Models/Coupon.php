<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Individual coupon code. NEVER change status directly — always go through CouponService,
 * which wraps transitions in DB::transaction with lockForUpdate.
 *
 * @property int $id
 * @property string $code
 * @property int $batch_id
 * @property int|null $member_id
 * @property string $category — discount / threshold / shipping (Phase 2.3)
 * @property int $priority — 多券同時套用順序,小→大 (Phase 2.3)
 * @property string $status — created / redeemed / cancelled / expired
 * @property int|null $redeemed_by
 * @property Carbon|null $redeemed_at
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property array|null $meta
 */
class Coupon extends Model
{
    public const STATUS_CREATED = 'created';

    public const STATUS_REDEEMED = 'redeemed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const CATEGORY_DISCOUNT  = 'discount';
    public const CATEGORY_THRESHOLD = 'threshold';
    public const CATEGORY_SHIPPING  = 'shipping';

    protected $fillable = [
        'code', 'batch_id', 'member_id', 'category', 'priority', 'status',
        'redeemed_by', 'redeemed_at',
        'cancelled_by', 'cancelled_at',
        'meta',
    ];

    protected $casts = [
        'priority'     => 'integer',
        'redeemed_at'  => 'datetime',
        'cancelled_at' => 'datetime',
        'meta'         => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CouponBatch::class, 'batch_id');
    }

    // The member this coupon is reserved for (null = open to any member)
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'redeemed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCreated(): bool
    {
        return $this->status === self::STATUS_CREATED;
    }

    public function isRedeemed(): bool
    {
        return $this->status === self::STATUS_REDEEMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'order_coupons')
            ->using(OrderCoupon::class)
            ->withPivot(['discount_applied', 'apply_order'])
            ->withTimestamps();
    }
}
