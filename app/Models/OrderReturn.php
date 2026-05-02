<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Return / exchange request lifecycle row.
 *
 * Class is named OrderReturn (not Return) because `return` is a PHP
 * reserved word. The underlying table is plain `returns`.
 *
 * Phase 2.3 scope: schema + Filament read-only list + basic status
 * toggles. Full pickup / quality_check flow is Phase 2.4.
 *
 * @property int         $id
 * @property int         $order_id
 * @property string      $return_no
 * @property string      $type     — 'return' | 'exchange'
 * @property string      $status
 * @property string      $reason
 * @property int|null    $refund_amount
 * @property int|null    $approved_by
 * @property \Carbon\Carbon|null $approved_at
 * @property \Carbon\Carbon|null $refunded_at
 * @property array|null  $meta
 */
class OrderReturn extends Model
{
    protected $table = 'returns';

    public const TYPE_RETURN   = 'return';
    public const TYPE_EXCHANGE = 'exchange';

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_PICKED_UP = 'picked_up';
    public const STATUS_RECEIVED  = 'received';
    public const STATUS_REFUNDED  = 'refunded';

    protected $fillable = [
        'order_id', 'return_no', 'type', 'status', 'reason',
        'refund_amount', 'approved_by', 'approved_at', 'refunded_at', 'meta',
    ];

    protected $casts = [
        'refund_amount' => 'integer',
        'approved_at'   => 'datetime',
        'refunded_at'   => 'datetime',
        'meta'          => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
