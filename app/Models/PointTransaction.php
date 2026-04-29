<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Audit log + idempotency record for every points change.
 *
 * NEVER write to this table directly — always go through PointService::adjust
 * which wraps the insert + members.points update in DB::transaction with
 * lockForUpdate. Direct insert risks the cache (members.points) drifting.
 *
 * @property int         $id
 * @property int         $member_id
 * @property int         $amount
 * @property int         $balance_after
 * @property string      $type       — earn / spend / adjust / expire / refund
 * @property string      $reason
 * @property string      $idempotency_key
 * @property int|null    $actor_id
 * @property string      $actor_type — user / system / order / coupon
 * @property string|null $source_type
 * @property int|null    $source_id
 * @property \Carbon\Carbon|null $expires_at
 * @property array|null  $meta
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class PointTransaction extends Model
{
    public const TYPE_EARN    = 'earn';
    public const TYPE_SPEND   = 'spend';
    public const TYPE_ADJUST  = 'adjust';
    public const TYPE_EXPIRE  = 'expire';
    public const TYPE_REFUND  = 'refund';

    public const ACTOR_USER   = 'user';
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_ORDER  = 'order';
    public const ACTOR_COUPON = 'coupon';

    protected $fillable = [
        'member_id', 'amount', 'balance_after', 'type', 'reason',
        'idempotency_key', 'actor_id', 'actor_type',
        'source_type', 'source_id', 'expires_at', 'meta',
    ];

    protected $casts = [
        'amount'        => 'integer',
        'balance_after' => 'integer',
        'expires_at'    => 'datetime',
        'meta'          => 'array',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * The admin user who executed this transaction (null for system / order / coupon).
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Polymorphic: Order / Coupon / null.
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
