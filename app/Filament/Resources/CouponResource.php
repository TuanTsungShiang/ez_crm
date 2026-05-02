<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CouponResource\Pages;
use App\Models\Coupon;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = '行銷工具';

    protected static ?string $navigationLabel = '優惠券代碼';

    protected static ?string $modelLabel = '優惠券代碼';

    protected static ?string $pluralModelLabel = '優惠券代碼';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('coupon.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('代碼')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('batch.name')
                    ->label('批次')
                    ->searchable()
                    ->url(fn (Coupon $r): string => CouponBatchResource::getUrl('view', ['record' => $r->batch])
                    ),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('狀態')
                    ->colors([
                        'gray' => 'created',
                        'success' => 'redeemed',
                        'warning' => 'cancelled',
                        'danger' => 'expired',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'created' => '未使用',
                        'redeemed' => '已核銷',
                        'cancelled' => '已取消',
                        'expired' => '已過期',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('redeemedBy.name')
                    ->label('核銷會員')
                    ->default('—'),

                Tables\Columns\TextColumn::make('redeemed_at')
                    ->label('核銷時間')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('cancelledBy.name')
                    ->label('取消操作者')
                    ->default('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('狀態')
                    ->options([
                        'created' => '未使用',
                        'redeemed' => '已核銷',
                        'cancelled' => '已取消',
                        'expired' => '已過期',
                    ]),

                Tables\Filters\SelectFilter::make('batch_id')
                    ->label('批次')
                    ->relationship('batch', 'name'),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoupons::route('/'),
        ];
    }

    public static function getRelations(): array
    {
        return [];
    }
}
