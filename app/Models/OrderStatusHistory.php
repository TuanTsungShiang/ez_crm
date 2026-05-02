<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log of every state transition on an Order.
 *
 * Never UPDATE — only INSERT a new row per transition. updated_at is
 * intentionally absent.
 *
 * @property int         $id
 * @property int         $order_id
 * @property string|null $from_status
 * @property string      $to_status
 * @property string|null $reason
 * @property int|null    $actor_id
 * @property string      $actor_type
 * @property array|null  $meta
 * @property \Carbon\Carbon $created_at
 */
class OrderStatusHistory extends Model
{
    public const UPDATED_AT = null;   // append-only

    protected $fillable = [
        'order_id', 'from_status', 'to_status', 'reason',
        'actor_id', 'actor_type', 'meta',
    ];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The User (admin) who performed this transition. Null for actor_type
     * 'member' (use Order::member instead) or 'system' (cron / webhook).
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
