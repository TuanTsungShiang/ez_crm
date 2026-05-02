<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inbound payment-provider webhook log + replay-defense row.
 *
 * Every ECPay (and future) callback is INSERTed here BEFORE we trust it.
 * The full raw body is preserved in `raw_payload` for audit / dispute /
 * forensic purposes regardless of verification outcome.
 *
 * Replay defense:
 *   - App layer: ECPayService.handleCallback short-circuits if a row
 *     with the same provider+trade_no is already 'processed'.
 *   - DB layer: unique(provider, trade_no, callback_time) catches
 *     concurrent callbacks that race past the app check.
 *
 * @property int         $id
 * @property int|null    $order_id
 * @property string      $provider
 * @property string      $trade_no
 * @property string      $rtn_code
 * @property string|null $rtn_msg
 * @property int|null    $amount
 * @property \Carbon\Carbon $callback_time
 * @property string      $status      — received|verified|processed|failed|duplicate
 * @property array       $raw_payload
 */
class PaymentCallback extends Model
{
    public const PROVIDER_ECPAY = 'ecpay';

    public const STATUS_RECEIVED  = 'received';
    public const STATUS_VERIFIED  = 'verified';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_DUPLICATE = 'duplicate';

    protected $fillable = [
        'order_id', 'provider', 'trade_no',
        'rtn_code', 'rtn_msg', 'amount',
        'callback_time', 'status', 'raw_payload',
    ];

    protected $casts = [
        'amount'        => 'integer',
        'callback_time' => 'datetime',
        'raw_payload'   => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
