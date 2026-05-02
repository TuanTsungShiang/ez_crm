<?php

namespace App\Filament\Resources\CouponBatchResource\Pages;

use App\Filament\Resources\CouponBatchResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCouponBatches extends ListRecords
{
    protected static string $resource = CouponBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
