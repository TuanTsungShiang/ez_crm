<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberGroupResource\Pages;
use App\Models\MemberGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MemberGroupResource extends Resource
{
    protected static ?string $model = MemberGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = '會員群組';

    protected static ?string $modelLabel = '群組';

    protected static ?string $pluralModelLabel = '群組';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('群組名稱')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(100),
                Forms\Components\TextInput::make('sort_order')
                    ->label('排序')
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
                Forms\Components\Textarea::make('description')
                    ->label('說明')
                    ->rows(3)
                    ->columnSpanFull(),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('群組名稱')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('說明')
                    ->limit(50)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('排序')
                    ->sortable(),
                Tables\Columns\TextColumn::make('members_count')
                    ->label('會員數')
                    ->counts('members')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (Tables\Actions\DeleteAction $action, MemberGroup $record) {
                        $count = $record->members()->count();
                        if ($count > 0) {
                            Notification::make()
                                ->danger()
                                ->title('無法刪除')
                                ->body("此群組下仍有 {$count} 位會員，請先移除會員或將會員移至其他群組。")
                                ->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMemberGroups::route('/'),
            'create' => Pages\CreateMemberGroup::route('/create'),
            'edit' => Pages\EditMemberGroup::route('/{record}/edit'),
        ];
    }
}
