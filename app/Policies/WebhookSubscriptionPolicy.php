<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookSubscription;

class WebhookSubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('webhook_subscription.view_any');
    }

    public function view(User $user, WebhookSubscription $sub): bool
    {
        return $user->can('webhook_subscription.view_any');
    }

    public function create(User $user): bool
    {
        return $user->can('webhook_subscription.manage');
    }

    public function update(User $user, WebhookSubscription $sub): bool
    {
        return $user->can('webhook_subscription.manage');
    }

    public function delete(User $user, WebhookSubscription $sub): bool
    {
        return $user->can('webhook_subscription.manage');
    }
}
