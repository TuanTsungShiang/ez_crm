<?php

namespace App\Providers;

use App\Events\Webhooks\CouponRedeemed;
use App\Events\Webhooks\MemberCreated;
use App\Events\Webhooks\MemberDeleted;
use App\Events\Webhooks\MemberLoggedIn;
use App\Events\Webhooks\MemberUpdated;
use App\Events\Webhooks\MemberVerifiedEmail;
use App\Events\Webhooks\OAuthBound;
use App\Events\Webhooks\OAuthUnbound;
use App\Events\Webhooks\PointAdjusted;
use App\Listeners\DispatchWebhook;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use SocialiteProviders\Discord\DiscordExtendSocialite;
use SocialiteProviders\Line\LineExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        SocialiteWasCalled::class => [
            LineExtendSocialite::class,
            DiscordExtendSocialite::class,
        ],

        // Webhook events — 共用同一個 DispatchWebhook listener
        MemberCreated::class => [DispatchWebhook::class],
        MemberUpdated::class => [DispatchWebhook::class],
        MemberDeleted::class => [DispatchWebhook::class],
        MemberVerifiedEmail::class => [DispatchWebhook::class],
        MemberLoggedIn::class => [DispatchWebhook::class],
        OAuthBound::class => [DispatchWebhook::class],
        OAuthUnbound::class => [DispatchWebhook::class],
        PointAdjusted::class => [DispatchWebhook::class],
        CouponRedeemed::class => [DispatchWebhook::class],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
