<?php

namespace App\Exceptions\Order;

use Exception;

/**
 * Thrown when cumulative refund_amount would exceed paid_amount.
 * Caller should map to HTTP 422 with ApiCode D004.
 */
class RefundAmountExceedsPaidException extends Exception
{
    public function __construct(int $requested, int $remaining)
    {
        parent::__construct(
            "Refund amount {$requested} exceeds remaining refundable amount {$remaining}"
        );
    }
}
