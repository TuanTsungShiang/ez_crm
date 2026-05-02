<?php

namespace App\Http\Middleware;

use App\Services\Payments\ECPay\ECPayService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce ECPay IP whitelist on the payment webhook endpoint.
 *
 * Only active in production (APP_ENV=production).  In all other environments
 * the middleware is a no-op so that sandbox testing works without additional
 * network configuration.
 *
 * Per ORDER_INTEGRATION_PLAN §9c (decision: C — prod-only enforcement).
 * The whitelist itself lives in config/ecpay.php and can be overridden via
 * the ECPAY_IP_WHITELIST env variable.
 */
class EcpayIpWhitelist
{
    public function __construct(private ECPayService $ecpay) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->ecpay->isAllowedIp($request->ip())) {
            abort(403, 'Forbidden: IP not in ECPay whitelist');
        }

        return $next($request);
    }
}
