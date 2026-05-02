<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $order_id
 * @property string $type   — 'shipping' | 'billing'
 * @property string $recipient_name
 * @property string $phone
 * @property string $country
 * @property string $postal_code
 * @property string $city
 * @property string $district
 * @property string $address_line
 * @property string|null $address_line2
 */
class OrderAddress extends Model
{
    public const TYPE_SHIPPING = 'shipping';
    public const TYPE_BILLING  = 'billing';

    protected $fillable = [
        'order_id', 'type',
        'recipient_name', 'phone',
        'country', 'postal_code', 'city', 'district',
        'address_line', 'address_line2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
