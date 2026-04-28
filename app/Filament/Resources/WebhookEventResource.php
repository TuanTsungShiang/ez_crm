<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebhookEventResource\Pages;
use App\Jobs\SendWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Models\WebhookSubscription;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WebhookEventResource extends Resource
{
    protected static ?string $model = WebhookEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationGroup = 'Webhooks';

    protected static ?string $navigationLabel = '事件列表';

    protected static ?string $modelLabel = 'Webhook 事件';

    protected static ?string $pluralModelLabel = 'Webhook 事件';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('webhook_event.view_any') ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Seq')
                    ->sortable(),

                Tables\Columns\TextColumn::make('event_type')
                    ->label('事件類型')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('deliveries_count')
                    ->label('派送數')
                    ->counts('deliveries')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('deliveries_success_count')
                    ->label('成功')
                    ->counts([
                        'deliveries' => fn ($q) => $q->where('status', 'success'),
                    ])
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('deliveries_failed_count')
                    ->label('失敗')
                    ->counts([
                        'deliveries' => fn ($q) => $q->where('status', 'failed'),
                    ])
                    ->badge()
                    ->color('danger'),

                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('發生時間')
                    ->dateTime('Y-m-d H:i:s.u')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event_type')
                    ->options(WebhookSubscriptionResource::availableEvents()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                // Replay to all subscribers — 把這個事件重新派發給目前所有訂閱者
                Tables\Actions\Action::make('replay')
                    ->label('重新派送')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('對這個事件,重新產生 delivery 並派送給目前所有有訂閱的 active subscribers(不論當初有沒有收過)。')
                    ->action(function (WebhookEvent $record) {
                        $type = $record->event_type;
                        $subs = WebhookSubscription::where('is_active', true)
                            ->where('is_circuit_broken', false)
                            ->whereJsonContains('events', $type)
                            ->get();

                        $count = 0;
                        foreach ($subs as $sub) {
                            $delivery = WebhookDelivery::create([
                                'webhook_event_id' => $record->id,
                                'subscription_id'  => $sub->id,
                                'status'           => WebhookDelivery::STATUS_PENDING,
                                'created_at'       => now(),
                            ]);
                            SendWebhookJob::dispatch($delivery->id)->onQueue('webhooks');
                            $count++;
                        }

                        Notification::make()
                            ->success()
                            ->title("已重新派送給 {$count} 個訂閱端")
                            ->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('事件資訊')
                    ->schema([
                        Infolists\Components\TextEntry::make('id')->label('Sequence'),
                        Infolists\Components\TextEntry::make('event_type')->label('類型')->badge(),
                        Infolists\Components\TextEntry::make('occurred_at')->dateTime('Y-m-d H:i:s.u'),
                    ])->columns(3),

                Infolists\Components\Section::make('Payload 快照')
                    ->schema([
                        Infolists\Components\TextEntry::make('payload')
                            ->label('')
                            ->formatStateUsing(fn ($state): string =>
                                json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                            )
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre']),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebhookEvents::route('/'),
            'view'  => Pages\ViewWebhookEvent::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
