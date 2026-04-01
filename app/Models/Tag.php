<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'color', 'description'];

    public function members()
    {
        return $this->belongsToMany(Member::class, 'member_tag');
    }
}
