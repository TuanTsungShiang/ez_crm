<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    protected $fillable = [
        'channel',
        'driver',
        'member_id',
        'to_address',
        'content',
        'purpose',
        'status',
        'provider_message_id',
        'credits_used',
        'error_message',
        'fallback_attempts',
        'sent_at',
        'delivered_at',
    ];

    protected $casts = [
        'fallback_attempts' => 'array',
        'credits_used'      => 'decimal:2',
        'sent_at'           => 'datetime',
        'delivered_at'      => 'datetime',
    ];

    public const STATUS_QUEUED    = 'queued';
    public const STATUS_SENT      = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_BOUNCED   = 'bounced';

    public const PURPOSE_OTP_LOGIN    = 'otp_login';
    public const PURPOSE_OTP_REGISTER = 'otp_register';
    public const PURPOSE_OTP_VERIFY   = 'otp_verify';
    public const PURPOSE_MARKETING    = 'marketing';
    public const PURPOSE_TRANSACTION  = 'transaction';
    public const PURPOSE_ALERT        = 'alert';

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
