<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MemberAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id', 'label',
        'recipient_name', 'recipient_phone',
        'country', 'zip_code', 'city', 'district', 'address',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
