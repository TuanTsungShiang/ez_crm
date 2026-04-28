<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebhookDeliveryResource\Pages;
use App\Jobs\SendWebhookJob;
use App\Models\WebhookDelivery;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WebhookDeliveryResource extends Resource
{
    protected static ?string $model = WebhookDelivery::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'Webhooks';

    protected static ?string $navigationLabel = '派送紀錄';

    protected static ?string $modelLabel = 'Webhook 派送';

    protected static ?string $pluralModelLabel = 'Webhook 派送紀錄';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('webhook_delivery.view_any') ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('webhookEvent.event_type')
                    ->label('事件')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('subscription.name')
                    ->label('訂閱')
                    ->searchable()
                    ->url(fn (WebhookDelivery $r): ?string =>
                        $r->subscription
                            ? WebhookSubscriptionResource::getUrl('edit', ['record' => $r->subscription])
                            : null,
                    ),

                Tables\Columns\TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success'  => 'success',
                        'failed'   => 'danger',
                        'retrying' => 'warning',
                        default    => 'gray',
                    }),

                Tables\Columns\TextColumn::make('attempts')
                    ->label('嘗試')
                    ->numeric(),

                Tables\Columns\TextColumn::make('http_status')
                    ->label('HTTP')
                    ->badge()
                    ->color(fn ($state): string =>
                        $state === null ? 'gray'
                        : ($state < 300 ? 'success' : ($state < 500 ? 'warning' : 'danger'))
                    ),

                Tables\Columns\TextColumn::make('next_retry_at')
                    ->label('下次重試')
                    ->dateTime('m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('delivered_at')
                    ->label('送達')
                    ->dateTime('m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立')
                    ->dateTime('m-d H:i:s')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('狀態')
                    ->options([
                        'pending'  => 'Pending',
                        'retrying' => 'Retrying',
                        'success'  => 'Success',
                        'failed'   => 'Failed',
                    ]),
                Tables\Filters\SelectFilter::make('subscription_id')
                    ->label('訂閱端')
                    ->relationship('subscription', 'name'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                // Retry — 只對 failed / retrying delivery 可用
                Tables\Actions\Action::make('retry')
                    ->label('重送')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (WebhookDelivery $r): bool =>
                        in_array($r->status, [WebhookDelivery::STATUS_FAILED, WebhookDelivery::STATUS_RETRYING])
                    )
                    ->requiresConfirmation()
                    ->action(function (WebhookDelivery $record) {
                        $record->update([
                            'status'        => WebhookDelivery::STATUS_PENDING,
                            'next_retry_at' => null,
                            'error_message' => null,
                        ]);
                        SendWebhookJob::dispatch($record->id)->onQueue('webhooks');
                        Notification::make()
                            ->success()
                            ->title('已重新排入 queue')
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('retry_bulk')
                    ->label('批次重送')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function ($records) {
                        $count = 0;
                        foreach ($records as $record) {
                            if (! in_array($record->status, [WebhookDelivery::STATUS_FAILED, WebhookDelivery::STATUS_RETRYING])) {
                                continue;
                            }
                            $record->update([
                                'status'        => WebhookDelivery::STATUS_PENDING,
                                'next_retry_at' => null,
                                'error_message' => null,
                            ]);
                            SendWebhookJob::dispatch($record->id)->onQueue('webhooks');
                            $count++;
                        }
                        Notification::make()
                            ->success()
                            ->title("已重送 {$count} 筆")
                            ->send();
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->poll('10s');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('派送概要')
                    ->schema([
                        Infolists\Components\TextEntry::make('id')->label('Delivery ID'),
                        Infolists\Components\TextEntry::make('webhookEvent.event_type')->label('事件類型'),
                        Infolists\Components\TextEntry::make('subscription.name')->label('訂閱端'),
                        Infolists\Components\TextEntry::make('subscription.url')->label('URL'),
                        Infolists\Components\TextEntry::make('status')->badge(),
                        Infolists\Components\TextEntry::make('attempts'),
                        Infolists\Components\TextEntry::make('http_status'),
                        Infolists\Components\TextEntry::make('delivered_at')->dateTime(),
                        Infolists\Components\TextEntry::make('next_retry_at')->dateTime(),
                        Infolists\Components\TextEntry::make('created_at')->dateTime(),
                    ])->columns(2),

                Infolists\Components\Section::make('Payload（送出）')
                    ->schema([
                        Infolists\Components\TextEntry::make('webhookEvent.payload')
                            ->label('')
                            ->formatStateUsing(fn ($state): string =>
                                json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                            )
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre']),
                    ]),

                Infolists\Components\Section::make('Response / Error')
                    ->schema([
                        Infolists\Components\TextEntry::make('response_body')
                            ->label('對方 Response Body（前 1000 字）')
                            ->placeholder('—')
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre']),
                        Infolists\Components\TextEntry::make('error_message')
                            ->label('例外訊息')
                            ->placeholder('—')
                            ->color('danger')
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebhookDeliveries::route('/'),
            'view'  => Pages\ViewWebhookDelivery::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // 系統自動寫入,人工不用建
    }
}
