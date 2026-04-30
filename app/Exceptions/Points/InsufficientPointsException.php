<?php

namespace App\Exceptions\Points;

use Exception;

/**
 * Thrown by PointService::adjust when a deduction would push the member's
 * balance below zero. Caller should map this to an HTTP 422 with ApiCode B001.
 */
class InsufficientPointsException extends Exception
{
    public function __construct(
        public readonly int $memberId,
        public readonly int $currentBalance,
        public readonly int $requestedAmount,
    ) {
        parent::__construct(sprintf(
            'Member %d has %d points, cannot deduct %d',
            $memberId,
            $currentBalance,
            abs($requestedAmount),
        ));
    }
}
