<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationDeliveryResource\Pages;
use App\Models\NotificationDelivery;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NotificationDeliveryResource extends Resource
{
    protected static ?string $model = NotificationDelivery::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = '通知管理';

    protected static ?string $navigationLabel = '發送紀錄';

    protected static ?string $modelLabel = '發送紀錄';

    protected static ?string $pluralModelLabel = '發送紀錄';

    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('notification_delivery.view_any') ?? false;
    }

    /** Read-only resource. */
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

    public static function form(Form $form): Form
    {
        // Only used by the View page; all fields disabled.
        return $form
            ->schema([
                Forms\Components\Section::make('基本資訊')
                    ->schema([
                        Forms\Components\TextInput::make('channel')->label('通道')->disabled(),
                        Forms\Components\TextInput::make('driver')->label('Driver')->disabled(),
                        Forms\Components\TextInput::make('purpose')->label('用途')->disabled(),
                        Forms\Components\TextInput::make('status')->label('狀態')->disabled(),
                        Forms\Components\TextInput::make('to_address')->label('收件人')->disabled(),
                        Forms\Components\TextInput::make('member_id')->label('會員 ID')->disabled(),
                    ])->columns(2),

                Forms\Components\Section::make('內容與結果')
                    ->schema([
                        Forms\Components\Textarea::make('content')->label('內容')->disabled()->rows(4),
                        Forms\Components\TextInput::make('provider_message_id')->label('Provider 訊息 ID')->disabled(),
                        Forms\Components\TextInput::make('credits_used')->label('Credits 用量')->disabled(),
                        Forms\Components\Textarea::make('error_message')->label('錯誤訊息')->disabled()->rows(3),
                    ]),

                Forms\Components\Section::make('時間軸')
                    ->schema([
                        Forms\Components\DateTimePicker::make('created_at')->label('建立')->disabled(),
                        Forms\Components\DateTimePicker::make('sent_at')->label('發送')->disabled(),
                        Forms\Components\DateTimePicker::make('delivered_at')->label('送達')->disabled(),
                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('channel')
                    ->label('通道')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sms'     => 'info',
                        'email'   => 'gray',
                        'line'    => 'success',
                        'fcm'     => 'warning',
                        'webhook' => 'primary',
                        default   => 'gray',
                    }),
                Tables\Columns\TextColumn::make('purpose')
                    ->label('用途')
                    ->searchable(),
                Tables\Columns\TextColumn::make('to_address')
                    ->label('收件人')
                    ->limit(30)
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'queued'    => 'gray',
                        'sent'      => 'info',
                        'delivered' => 'success',
                        'failed'    => 'danger',
                        'bounced'   => 'danger',
                        default     => 'gray',
                    }),
                Tables\Columns\TextColumn::make('credits_used')
                    ->label('Credits')
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('sent_at')
                    ->label('發送時間')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')
                    ->options([
                        'sms'     => 'SMS',
                        'email'   => 'Email',
                        'line'    => 'LINE',
                        'fcm'     => 'FCM',
                        'webhook' => 'Webhook',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'queued'    => 'Queued',
                        'sent'      => 'Sent',
                        'delivered' => 'Delivered',
                        'failed'    => 'Failed',
                        'bounced'   => 'Bounced',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationDeliveries::route('/'),
            'view'  => Pages\ViewNotificationDelivery::route('/{record}'),
        ];
    }
}
