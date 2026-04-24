<?php

namespace App\Providers;

use App\Services\Sms\SmsManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsManager::class, function ($app) {
            return new SmsManager($app, $app['config']);
        });
    }

    public function boot(): void
    {
        //
    }
}
