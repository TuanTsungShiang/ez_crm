<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MemberProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id', 'avatar', 'gender',
        'birthday', 'bio', 'language', 'timezone',
    ];

    protected $casts = [
        'birthday' => 'date',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
