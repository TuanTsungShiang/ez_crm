<?php

namespace App\Filament\Resources\WebhookSubscriptionResource\Pages;

use App\Filament\Resources\WebhookSubscriptionResource;
use App\Models\WebhookSubscription;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateWebhookSubscription extends CreateRecord
{
    protected static string $resource = WebhookSubscriptionResource::class;

    /**
     * 建立前塞入 secret + created_by。
     * Secret 之後會在 afterCreate 跳 notification 顯示一次。
     */
    protected ?string $generatedSecret = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->generatedSecret = WebhookSubscription::generateSecret();
        $data['secret'] = $this->generatedSecret;
        $data['created_by'] = auth()->id();
        return $data;
    }

    /**
     * 建立成功後跳 persistent notification 顯示 secret(只顯示一次)。
     */
    protected function afterCreate(): void
    {
        if (! $this->generatedSecret) {
            return;
        }

        Notification::make()
            ->success()
            ->title('訂閱建立成功,請複製 Secret(只顯示此一次)')
            ->body($this->generatedSecret)
            ->persistent()
            ->send();
    }
}
