<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookDelivery;

class WebhookDeliveryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('webhook_delivery.view_any');
    }

    public function view(User $user, WebhookDelivery $delivery): bool
    {
        return $user->can('webhook_delivery.view_any');
    }

    /**
     * Custom action: retry a failed delivery.
     * Used by Filament action ->visible(fn($record) => Gate::allows('retry', $record)).
     */
    public function retry(User $user, WebhookDelivery $delivery): bool
    {
        return $user->can('webhook_delivery.retry');
    }
}
