<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default SMS Driver
    |--------------------------------------------------------------------------
    |
    | dev / test 預設用 log(訊息寫進 laravel.log,完全免費)。
    | production 換成真正的 gateway(mitake / every8d / twilio...)時改 env。
    | 測試用 null(不輸出任何東西)。
    */

    'default' => env('SMS_DRIVER', 'log'),

    'drivers' => [
        'log' => [
            'class' => \App\Services\Sms\Drivers\LogDriver::class,
        ],
        'null' => [
            'class' => \App\Services\Sms\Drivers\NullDriver::class,
        ],
        // Phase 8.5 再接 Mitake:
        // 'mitake' => [
        //     'class'    => \App\Services\Sms\Drivers\MitakeDriver::class,
        //     'username' => env('MITAKE_USERNAME'),
        //     'password' => env('MITAKE_PASSWORD'),
        //     'endpoint' => env('MITAKE_ENDPOINT', 'http://smsapi.mitake.com.tw/api/mtk/SmSend'),
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OTP 相關
    |--------------------------------------------------------------------------
    */

    'otp' => [
        'template' => env('SMS_OTP_TEMPLATE', '【ez_crm】您的驗證碼是 {code},{minutes} 分鐘內有效。'),
    ],
];
