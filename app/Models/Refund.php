<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per refund event against an Order.
 *
 * Created by OrderService::refund / partialRefund inside the same DB
 * transaction that adjusts orders.refund_amount, orders.points_refunded,
 * and writes the reverse PointTransaction (linked via point_transaction_id).
 *
 * Direct INSERT/UPDATE forbidden — use OrderService.
 *
 * @property int         $id
 * @property int         $order_id
 * @property int         $amount
 * @property string      $type        — 'partial' | 'full'
 * @property string      $reason
 * @property int         $processed_by
 * @property \Carbon\Carbon $processed_at
 * @property string|null $ecpay_refund_no
 * @property string|null $ecpay_status
 * @property int|null    $point_transaction_id
 * @property array|null  $meta
 */
class Refund extends Model
{
    public const TYPE_PARTIAL = 'partial';
    public const TYPE_FULL    = 'full';

    public const ECPAY_PENDING = 'pending';
    public const ECPAY_SUCCESS = 'success';
    public const ECPAY_FAILED  = 'failed';

    protected $fillable = [
        'order_id', 'amount', 'type', 'reason',
        'processed_by', 'processed_at',
        'ecpay_refund_no', 'ecpay_status',
        'point_transaction_id', 'meta',
    ];

    protected $casts = [
        'amount'       => 'integer',
        'processed_at' => 'datetime',
        'meta'         => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function pointTransaction(): BelongsTo
    {
        return $this->belongsTo(PointTransaction::class);
    }
}
