<?php

namespace App\Filament\Resources\MemberGroupResource\Pages;

use App\Filament\Resources\MemberGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMemberGroup extends EditRecord
{
    protected static string $resource = MemberGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
