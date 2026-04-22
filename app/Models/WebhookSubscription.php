<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WebhookSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'url',
        'events',
        'secret',
        'previous_secret',
        'previous_secret_expires_at',
        'is_active',
        'is_circuit_broken',
        'consecutive_failure_count',
        'max_retries',
        'timeout_seconds',
        'created_by',
    ];

    protected $casts = [
        'events'                     => 'array',
        'is_active'                  => 'boolean',
        'is_circuit_broken'          => 'boolean',
        'previous_secret_expires_at' => 'datetime',
    ];

    protected $hidden = [
        'secret',
        'previous_secret',
    ];

    /**
     * 產生新 secret;rotation 時會把原 secret 存到 previous_secret。
     */
    public static function generateSecret(): string
    {
        return Str::random(64);
    }

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 是否可以接收派送:未停用且未斷路。
     */
    public function canReceive(): bool
    {
        return $this->is_active && ! $this->is_circuit_broken;
    }
}
