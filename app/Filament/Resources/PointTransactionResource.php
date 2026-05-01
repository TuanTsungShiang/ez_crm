<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PointTransactionResource\Pages;
use App\Models\PointTransaction;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PointTransactionResource extends Resource
{
    protected static ?string $model = PointTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = '會員管理';

    protected static ?string $navigationLabel = '點數流水帳';

    protected static ?string $modelLabel = '點數交易';

    protected static ?string $pluralModelLabel = '點數流水帳';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('points.view') ?? false;
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('member.name')
                    ->label('會員')
                    ->searchable()
                    ->url(fn (PointTransaction $r): string =>
                        MemberResource::getUrl('edit', ['record' => $r->member])
                    ),

                Tables\Columns\TextColumn::make('amount')
                    ->label('金額')
                    ->numeric()
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '') . number_format($state)),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('餘額後')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => number_format($state)),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('類型')
                    ->colors([
                        'success' => 'earn',
                        'danger'  => 'spend',
                        'warning' => 'adjust',
                        'gray'    => 'expire',
                        'info'    => 'refund',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'earn'   => '加點',
                        'spend'  => '扣點',
                        'adjust' => '調整',
                        'expire' => '過期',
                        'refund' => '退款',
                        default  => $state,
                    }),

                Tables\Columns\TextColumn::make('reason')
                    ->label('說明')
                    ->limit(40)
                    ->searchable(),

                Tables\Columns\TextColumn::make('actor.name')
                    ->label('操作者')
                    ->default('系統')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('actor_type')
                    ->label('來源')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'user'   => '後台',
                        'system' => '系統',
                        'order'  => '訂單',
                        'coupon' => '優惠券',
                        default  => $state,
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('idempotency_key')
                    ->label('冪等 Key')
                    ->limit(20)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('時間')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('類型')
                    ->options([
                        'earn'   => '加點',
                        'spend'  => '扣點',
                        'adjust' => '調整',
                        'expire' => '過期',
                        'refund' => '退款',
                    ]),

                Tables\Filters\SelectFilter::make('actor_type')
                    ->label('來源')
                    ->options([
                        'user'   => '後台操作',
                        'system' => '系統',
                        'order'  => '訂單',
                        'coupon' => '優惠券',
                    ]),

                Tables\Filters\Filter::make('created_at')
                    ->label('時間範圍')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('從'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('到'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'],  fn ($q) => $q->whereDate('created_at', '>=', $data['from']))
                            ->when($data['until'], fn ($q) => $q->whereDate('created_at', '<=', $data['until']));
                    }),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPointTransactions::route('/'),
        ];
    }

    public static function getRelations(): array
    {
        return [];
    }
}
