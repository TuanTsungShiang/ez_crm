<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row configuration for the orders domain.
 *
 * Use OrderSettings::current() — never instantiate or insert. The seed
 * row is created by the migration. Updates go through Filament admin
 * UI (Phase 2.3 Day 7) which writes back to the existing row.
 *
 * @property int    $id
 * @property string $order_no_prefix
 * @property float  $points_rate
 * @property int    $pending_timeout_minutes
 * @property int    $min_charge_amount
 */
class OrderSettings extends Model
{
    protected $table = 'order_settings';

    protected $fillable = [
        'order_no_prefix',
        'points_rate',
        'pending_timeout_minutes',
        'min_charge_amount',
    ];

    protected $casts = [
        'points_rate'             => 'float',
        'pending_timeout_minutes' => 'integer',
        'min_charge_amount'       => 'integer',
    ];

    public static function current(): self
    {
        return self::firstOrFail();
    }
}
