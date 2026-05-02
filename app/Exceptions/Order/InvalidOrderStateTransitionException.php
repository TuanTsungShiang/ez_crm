<?php

namespace App\Exceptions\Order;

use Exception;

/**
 * Thrown by OrderService when a state transition violates the FSM whitelist.
 * Caller should map to HTTP 422 with ApiCode D001.
 */
class InvalidOrderStateTransitionException extends Exception
{
    public function __construct(
        public readonly string $currentStatus,
        public readonly string $attemptedAction,
    ) {
        parent::__construct(
            "Cannot perform '{$attemptedAction}' on order with status '{$currentStatus}'"
        );
    }
}
