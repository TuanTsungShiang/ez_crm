<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CouponBatchResource\Pages;
use App\Models\CouponBatch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CouponBatchResource extends Resource
{
    protected static ?string $model = CouponBatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = '行銷工具';

    protected static ?string $navigationLabel = '優惠券批次';

    protected static ?string $modelLabel = '優惠券批次';

    protected static ?string $pluralModelLabel = '優惠券批次';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('coupon.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('coupon.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('批次設定')->schema([
                Forms\Components\TextInput::make('name')
                    ->label('批次名稱')
                    ->required()
                    ->maxLength(100),

                Forms\Components\Textarea::make('description')
                    ->label('說明')
                    ->nullable()
                    ->maxLength(500)
                    ->rows(2),

                Forms\Components\Select::make('type')
                    ->label('類型')
                    ->options([
                        'discount_amount' => '折抵金額（NT$）',
                        'discount_percent' => '折扣百分比（%）',
                        'points' => '兌換點數',
                    ])
                    ->required(),

                Forms\Components\TextInput::make('value')
                    ->label('數值')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->helperText('折抵金額填 NT 整數 / 折扣填 1-100 / 點數填整數'),

                Forms\Components\TextInput::make('quantity')
                    ->label('產生張數')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->maxValue(10000),

                Forms\Components\DateTimePicker::make('starts_at')
                    ->label('開始時間')
                    ->nullable(),

                Forms\Components\DateTimePicker::make('expires_at')
                    ->label('到期時間')
                    ->nullable()
                    ->after('starts_at'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('批次名稱')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('類型')
                    ->colors([
                        'success' => 'discount_amount',
                        'warning' => 'discount_percent',
                        'info' => 'points',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'discount_amount' => '折抵金額',
                        'discount_percent' => '折扣%',
                        'points' => '點數',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('value')
                    ->label('數值')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('總張數')
                    ->numeric(),

                Tables\Columns\TextColumn::make('coupons_count')
                    ->label('已核銷')
                    ->counts(['coupons' => fn ($q) => $q->where('status', 'redeemed')])
                    ->numeric(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('到期')
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->color(fn ($record) => $record?->isExpired() ? 'danger' : null),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('類型')
                    ->options([
                        'discount_amount' => '折抵金額',
                        'discount_percent' => '折扣%',
                        'points' => '點數',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCouponBatches::route('/'),
            'create' => Pages\CreateCouponBatch::route('/create'),
            'view' => Pages\ViewCouponBatch::route('/{record}'),
        ];
    }
}
