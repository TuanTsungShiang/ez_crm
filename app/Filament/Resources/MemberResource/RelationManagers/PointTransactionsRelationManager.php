<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Models\PointTransaction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PointTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'pointTransactions';

    protected static ?string $title = '點數交易紀錄';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('amount')
                    ->label('金額')
                    ->numeric()
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '') . number_format($state)),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('餘額')
                    ->numeric()
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
                    ->limit(50),

                Tables\Columns\TextColumn::make('actor.name')
                    ->label('操作者')
                    ->default('系統'),

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
            ])
            ->actions([])
            ->bulkActions([]);
    }
}
