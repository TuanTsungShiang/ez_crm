<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookEvent;

class WebhookEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('webhook_event.view_any');
    }

    public function view(User $user, WebhookEvent $event): bool
    {
        return $user->can('webhook_event.view_any');
    }
}
