<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\Member::class                  => \App\Policies\MemberPolicy::class,
        \App\Models\MemberGroup::class             => \App\Policies\MemberGroupPolicy::class,
        \App\Models\Tag::class                     => \App\Policies\TagPolicy::class,
        \App\Models\WebhookSubscription::class     => \App\Policies\WebhookSubscriptionPolicy::class,
        \App\Models\WebhookDelivery::class         => \App\Policies\WebhookDeliveryPolicy::class,
        \App\Models\WebhookEvent::class            => \App\Policies\WebhookEventPolicy::class,
        \App\Models\User::class                    => \App\Policies\UserPolicy::class,
        \Spatie\Permission\Models\Role::class      => \App\Policies\RolePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // super_admin short-circuits all permission checks.
        // Returning null lets normal Gate/Policy logic run for everyone else.
        Gate::before(fn ($user) => $user->hasRole('super_admin') ? true : null);
    }
}
