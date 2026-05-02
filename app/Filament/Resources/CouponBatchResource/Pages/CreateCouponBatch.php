<?php

namespace App\Filament\Resources\CouponBatchResource\Pages;

use App\Filament\Resources\CouponBatchResource;
use App\Services\Coupon\CouponService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCouponBatch extends CreateRecord
{
    protected static string $resource = CouponBatchResource::class;

    // Override handleRecordCreation so we use CouponService::createBatch
    // which bulk-inserts the coupon codes atomically.
    protected function handleRecordCreation(array $data): Model
    {
        return app(CouponService::class)->createBatch($data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
