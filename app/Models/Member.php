<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Member extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    const STATUS_ACTIVE   = 1;
    const STATUS_INACTIVE = 0;
    const STATUS_PENDING  = 2;

    protected $fillable = [
        'uuid', 'member_group_id', 'name', 'nickname',
        'email', 'phone', 'password', 'password_set_at',
        'email_verified_at', 'phone_verified_at',
        'status', 'last_login_at', 'points',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'password_set_at'   => 'datetime',
        'last_login_at'     => 'datetime',
        'password'          => 'hashed',
        'points'            => 'integer',
    ];

    /**
     * Whether this member has set a real (user-chosen) password.
     * False for OAuth-only signups whose password is Str::random placeholder.
     */
    public function hasLocalPassword(): bool
    {
        return $this->password_set_at !== null;
    }

    public function group(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MemberGroup::class, 'member_group_id');
    }

    public function profile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(MemberProfile::class);
    }

    public function sns(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MemberSns::class);
    }

    public function tags(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'member_tag')->withPivot('created_at');
    }

    public function verifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MemberVerification::class);
    }

    public function addresses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MemberAddress::class);
    }

    public function loginHistories(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MemberLoginHistory::class);
    }

    public function devices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MemberDevice::class);
    }

    public function pointTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function hasVerifiedEmail(): bool
    {
        return ! is_null($this->email_verified_at);
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill(['email_verified_at' => now()])->save();
    }
}
