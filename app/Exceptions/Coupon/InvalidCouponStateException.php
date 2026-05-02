<?php

namespace App\Exceptions\Coupon;

use Exception;

/**
 * Thrown by CouponService when a state transition is not allowed.
 * Caller should map to HTTP 422 with ApiCode C001.
 */
class InvalidCouponStateException extends Exception
{
    public function __construct(
        public readonly string $currentStatus,
        public readonly string $attemptedAction,
    ) {
        parent::__construct(
            "Cannot perform '{$attemptedAction}' on coupon with status '{$currentStatus}'"
        );
    }
}
