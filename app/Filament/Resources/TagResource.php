<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TagResource\Pages;
use App\Models\Tag;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = '標籤管理';

    protected static ?string $modelLabel = '標籤';

    protected static ?string $pluralModelLabel = '標籤';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('標籤名稱')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(100),
                Forms\Components\ColorPicker::make('color')
                    ->label('顏色')
                    ->default('#3B82F6'),
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
                    ->label('標籤名稱')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color(fn (Tag $record): string => $record->color ?? 'gray')
                    ->formatStateUsing(fn (string $state): string => $state),
                Tables\Columns\ColorColumn::make('color')
                    ->label('顏色'),
                Tables\Columns\TextColumn::make('description')
                    ->label('說明')
                    ->limit(50)
                    ->toggleable(),
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
            ->defaultSort('name')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (Tables\Actions\DeleteAction $action, Tag $record) {
                        $count = $record->members()->count();
                        if ($count > 0) {
                            Notification::make()
                                ->danger()
                                ->title('無法刪除')
                                ->body("此標籤仍有 {$count} 位會員使用中，請先從會員身上移除此標籤。")
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
            'index' => Pages\ListTags::route('/'),
            'create' => Pages\CreateTag::route('/create'),
            'edit' => Pages\EditTag::route('/{record}/edit'),
        ];
    }
}
