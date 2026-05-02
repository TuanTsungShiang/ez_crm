<?php

namespace App\Exceptions\Payments;

use Exception;

/**
 * Thrown when an inbound payment-provider webhook fails signature verification.
 * Caller should NOT return an error to ECPay (which would trigger a retry);
 * instead log it, mark the callback row as 'failed', and return 200.
 */
class PaymentSignatureException extends Exception
{
    public function __construct(string $provider = 'ecpay')
    {
        parent::__construct("Payment callback signature verification failed for provider '{$provider}'");
    }
}
