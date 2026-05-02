<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property string $type — discount_amount / discount_percent / points
 * @property int $value
 * @property int $quantity
 * @property int|null $created_by
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 */
class CouponBatch extends Model
{
    public const TYPE_DISCOUNT_AMOUNT = 'discount_amount';

    public const TYPE_DISCOUNT_PERCENT = 'discount_percent';

    public const TYPE_POINTS = 'points';

    protected $fillable = [
        'uuid', 'name', 'description', 'type', 'value',
        'quantity', 'created_by', 'starts_at', 'expires_at',
    ];

    protected $casts = [
        'value' => 'integer',
        'quantity' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class, 'batch_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasStarted(): bool
    {
        return $this->starts_at === null || $this->starts_at->isPast();
    }
}
