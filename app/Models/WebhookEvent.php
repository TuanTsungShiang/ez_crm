<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'payload',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
