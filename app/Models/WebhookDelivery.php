<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    use HasFactory;

    const STATUS_PENDING  = 'pending';
    const STATUS_RETRYING = 'retrying';
    const STATUS_SUCCESS  = 'success';
    const STATUS_FAILED   = 'failed';

    public $timestamps = false;

    protected $fillable = [
        'webhook_event_id',
        'subscription_id',
        'status',
        'attempts',
        'http_status',
        'response_body',
        'error_message',
        'next_retry_at',
        'delivered_at',
        'created_at',
    ];

    protected $casts = [
        'next_retry_at' => 'datetime',
        'delivered_at'  => 'datetime',
        'created_at'    => 'datetime',
    ];

    public function webhookEvent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }

    public function subscription(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }
}
