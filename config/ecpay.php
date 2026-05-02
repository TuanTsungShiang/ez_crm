<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ECPay (綠界) Payment Gateway Configuration
    |--------------------------------------------------------------------------
    |
    | Phase 2.3 implements L1 (receive paid webhook only).
    | L2 (refund API) and L3 (multi-payment-method / reconciliation) are
    | deferred to Phase 2.4 / Phase 7.
    |
    | Sandbox credentials are public test credentials from ECPay docs.
    | Production credentials require a signed merchant agreement.
    */

    'merchant_id' => env('ECPAY_MERCHANT_ID', '2000132'),
    'hash_key' => env('ECPAY_HASH_KEY', '5294y06JbISpM5x9'),
    'hash_iv' => env('ECPAY_HASH_IV', 'v77hoKGq4kWxNNIS'),

    // 'stage' uses the public ECPay sandbox; 'production' uses live endpoint
    'env' => env('ECPAY_ENV', 'stage'),

    'endpoints' => [
        'stage' => 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5',
        'production' => 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5',
    ],

    // Backend notify URL — ECPay POSTs payment result here
    'return_url' => env('ECPAY_RETURN_URL', 'https://crm.example.com/api/v1/webhooks/ecpay/payment'),

    // Frontend redirect after payment completes (optional)
    'client_back_url' => env('ECPAY_CLIENT_BACK_URL', 'https://crm.example.com/orders/result'),

    /*
    |--------------------------------------------------------------------------
    | IP Whitelist (production only — per ORDER_INTEGRATION_PLAN §9c)
    |--------------------------------------------------------------------------
    |
    | Only enforced when APP_ENV=production. The middleware reads this list.
    | Update periodically from ECPay official documentation.
    | Supports single IPs and CIDR ranges.
    |
    | Latest list: https://www.ecpay.com.tw/Service/API_Dwnld
    */
    'ip_whitelist' => array_filter(explode(',', env('ECPAY_IP_WHITELIST', '203.66.91.31,203.66.91.32,203.66.91.33,203.66.91.34,203.66.91.35,203.66.94.98,203.66.94.99,103.52.180.0/22'))),

];
