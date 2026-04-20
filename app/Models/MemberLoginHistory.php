<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MemberLoginHistory extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'member_id', 'ip_address', 'user_agent',
        'platform', 'login_method', 'status', 'created_at',
    ];

    protected $casts = [
        'status'     => 'boolean',
        'created_at' => 'datetime',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
