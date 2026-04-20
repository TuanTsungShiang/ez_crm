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

    const STATUS_ACTIVE    = 1;
    const STATUS_INACTIVE  = 0;
    const STATUS_SUSPENDED = 2;

    protected $fillable = [
        'uuid', 'member_group_id', 'name', 'nickname',
        'email', 'phone', 'password',
        'email_verified_at', 'phone_verified_at',
        'status', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
        'password'          => 'hashed',
    ];

    public function group()
    {
        return $this->belongsTo(MemberGroup::class, 'member_group_id');
    }

    public function profile()
    {
        return $this->hasOne(MemberProfile::class);
    }

    public function sns()
    {
        return $this->hasMany(MemberSns::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'member_tag')->withPivot('created_at');
    }

    public function verifications()
    {
        return $this->hasMany(MemberVerification::class);
    }

    public function addresses()
    {
        return $this->hasMany(MemberAddress::class);
    }

    public function loginHistories()
    {
        return $this->hasMany(MemberLoginHistory::class);
    }

    public function devices()
    {
        return $this->hasMany(MemberDevice::class);
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
