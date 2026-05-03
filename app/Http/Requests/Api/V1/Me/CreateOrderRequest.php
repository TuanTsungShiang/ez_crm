<?php

namespace App\Http\Requests\Api\V1\Me;

use App\Http\Requests\Api\V1\BaseApiRequest;

class CreateOrderRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            // Line items (min 1, max 50)
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_sku' => ['required', 'string', 'max:64'],
            'items.*.product_name' => ['required', 'string', 'max:200'],
            'items.*.unit_price' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.product_meta' => ['nullable', 'array'],

            // Shipping address (required)
            'shipping_address' => ['required', 'array'],
            'shipping_address.recipient_name' => ['required', 'string', 'max:100'],
            'shipping_address.phone' => ['required', 'string', 'max:32'],
            'shipping_address.postal_code' => ['required', 'string', 'max:16'],
            'shipping_address.city' => ['required', 'string', 'max:64'],
            'shipping_address.district' => ['required', 'string', 'max:64'],
            'shipping_address.address_line' => ['required', 'string', 'max:200'],
            'shipping_address.address_line2' => ['nullable', 'string', 'max:200'],

            // Billing address (optional — falls back to shipping if omitted)
            'billing_address' => ['nullable', 'array'],
            'billing_address.recipient_name' => ['required_with:billing_address', 'string', 'max:100'],
            'billing_address.phone' => ['required_with:billing_address', 'string', 'max:32'],
            'billing_address.postal_code' => ['required_with:billing_address', 'string', 'max:16'],
            'billing_address.city' => ['required_with:billing_address', 'string', 'max:64'],
            'billing_address.district' => ['required_with:billing_address', 'string', 'max:64'],
            'billing_address.address_line' => ['required_with:billing_address', 'string', 'max:200'],
            'billing_address.address_line2' => ['nullable', 'string', 'max:200'],

            // Coupon codes (optional, multiple)
            'coupon_codes' => ['nullable', 'array', 'max:10'],
            'coupon_codes.*' => ['string', 'max:32'],
        ];
    }

    /** Extract the Idempotency-Key header value (empty string if absent). */
    public function idempotencyKey(): string
    {
        return (string) $this->header('Idempotency-Key', '');
    }
}
