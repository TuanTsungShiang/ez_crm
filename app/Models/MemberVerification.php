<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MemberVerification extends Model
{
    use HasFactory;

    const TYPE_EMAIL          = 'email';
    const TYPE_PHONE          = 'phone';
    const TYPE_PASSWORD_RESET = 'password_reset';
    const TYPE_EMAIL_CHANGE   = 'email_change';

    public $timestamps = false;

    protected $fillable = [
        'member_id', 'type', 'token',
        'expires_at', 'verified_at', 'created_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'verified_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return ! is_null($this->verified_at);
    }
}
