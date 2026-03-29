<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'member_group_id', 'name', 'nickname',
        'email', 'phone', 'password',
        'email_verified_at', 'phone_verified_at',
        'status', 'last_login_at',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
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
}
