<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebhookSubscriptionResource\Pages;
use App\Models\WebhookSubscription;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WebhookSubscriptionResource extends Resource
{
    protected static ?string $model = WebhookSubscription::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Webhooks';

    protected static ?string $navigationLabel = '訂閱管理';

    protected static ?string $modelLabel = 'Webhook 訂閱';

    protected static ?string $pluralModelLabel = 'Webhook 訂閱';

    protected static ?int $navigationSort = 10;

    /**
     * 事件類型清單（未來加新 event 要在這裡同步）
     */
    public static function availableEvents(): array
    {
        return [
            'member.created'        => 'member.created（會員註冊）',
            'member.email_verified' => 'member.email_verified（Email 驗證完成）',
            'member.logged_in'      => 'member.logged_in（會員登入）',
            'member.updated'        => 'member.updated（會員更新）',
            'member.deleted'        => 'member.deleted（會員刪除）',
            'oauth.bound'           => 'oauth.bound（綁定第三方）',
            'oauth.unbound'         => 'oauth.unbound（解除第三方）',
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('webhook_subscription.view_any') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('基本資訊')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('名稱')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('game-hub 玩家同步'),

                        Forms\Components\TextInput::make('url')
                            ->label('接收端 URL')
                            ->required()
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://example.com/webhook'),

                        Forms\Components\Select::make('events')
                            ->label('訂閱事件')
                            ->required()
                            ->multiple()
                            ->options(self::availableEvents())
                            ->hint('可複選')
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('傳送設定')
                    ->schema([
                        Forms\Components\TextInput::make('max_retries')
                            ->label('最大重試次數')
                            ->required()
                            ->numeric()
                            ->default(5)
                            ->minValue(1)
                            ->maxValue(20),

                        Forms\Components\TextInput::make('timeout_seconds')
                            ->label('HTTP Timeout（秒）')
                            ->required()
                            ->numeric()
                            ->default(10)
                            ->minValue(1)
                            ->maxValue(60),

                        Forms\Components\Toggle::make('is_active')
                            ->label('啟用')
                            ->default(true)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('name')
                    ->label('名稱')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->limit(40)
                    ->tooltip(fn (WebhookSubscription $r): string => $r->url),

                Tables\Columns\TextColumn::make('events')
                    ->label('訂閱事件')
                    ->badge()
                    ->separator(',')
                    ->color('gray'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('啟用')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\IconColumn::make('is_circuit_broken')
                    ->label('斷路')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-shield-check')
                    ->trueColor('danger')
                    ->falseColor('success'),

                Tables\Columns\TextColumn::make('consecutive_failure_count')
                    ->label('連續失敗')
                    ->numeric()
                    ->badge()
                    ->color(fn ($state): string => $state >= 10 ? 'danger' : ($state >= 5 ? 'warning' : 'gray')),

                Tables\Columns\TextColumn::make('deliveries_count')
                    ->label('派送總數')
                    ->counts('deliveries')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('啟用狀態'),
                Tables\Filters\TernaryFilter::make('is_circuit_broken')->label('斷路狀態'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // Rotate Secret — 產新 secret，舊 secret 保留 24h 過渡期
                Tables\Actions\Action::make('rotate_secret')
                    ->label('Rotate Secret')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(
                        '產生新 secret 後舊 secret 會保留 24 小時雙驗證過渡期。'
                        . '新的 secret 只會顯示一次，記得先把下游服務切過去。'
                    )
                    ->action(function (WebhookSubscription $record) {
                        $newSecret = WebhookSubscription::generateSecret();
                        $record->update([
                            'previous_secret'            => $record->secret,
                            'previous_secret_expires_at' => now()->addHours(24),
                            'secret'                     => $newSecret,
                        ]);
                        Notification::make()
                            ->success()
                            ->title('Secret 已輪換')
                            ->body("新 secret（只顯示此一次）：\n{$newSecret}")
                            ->persistent()
                            ->send();
                    }),

                // Reset Circuit Breaker — 僅 is_circuit_broken=true 時可用
                Tables\Actions\Action::make('reset_breaker')
                    ->label('解除斷路')
                    ->icon('heroicon-o-shield-check')
                    ->color('success')
                    ->visible(fn (WebhookSubscription $r): bool => $r->is_circuit_broken)
                    ->requiresConfirmation()
                    ->modalDescription('將清除斷路狀態並歸零連續失敗計數，之後新事件會重新嘗試派送。')
                    ->action(function (WebhookSubscription $record) {
                        $record->update([
                            'is_circuit_broken'         => false,
                            'consecutive_failure_count' => 0,
                        ]);
                        Notification::make()
                            ->success()
                            ->title('已解除斷路')
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWebhookSubscriptions::route('/'),
            'create' => Pages\CreateWebhookSubscription::route('/create'),
            'edit'   => Pages\EditWebhookSubscription::route('/{record}/edit'),
        ];
    }
}
