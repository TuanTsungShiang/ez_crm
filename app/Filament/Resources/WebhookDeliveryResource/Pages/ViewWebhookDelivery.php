<?php

namespace App\Filament\Resources\WebhookDeliveryResource\Pages;

use App\Filament\Resources\WebhookDeliveryResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewWebhookDelivery extends ViewRecord
{
    protected static string $resource = WebhookDeliveryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
