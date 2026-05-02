<?php

namespace App\Exceptions\Coupon;

use Exception;

/**
 * Thrown when the coupon's batch has passed its expires_at.
 * Caller should map to HTTP 422 with ApiCode C002.
 */
class CouponExpiredException extends Exception
{
    public function __construct(string $code)
    {
        parent::__construct("Coupon '{$code}' has expired");
    }
}
