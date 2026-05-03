<?php

namespace App\Filament\Resources;

use App\Exceptions\Order\InvalidOrderStateTransitionException;
use App\Exceptions\Order\RefundAmountExceedsPaidException;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Services\Order\OrderService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only Filament admin UI for Orders.
 *
 * State transitions (ship / complete / cancel / refund) route through
 * OrderService via custom Table Actions — never direct model writes.
 * This enforces the "Service-only writes" rule from ARCHITECTURE.md.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = '訂單管理';

    protected static ?string $navigationLabel = '訂單';

    protected static ?string $modelLabel = '訂單';

    protected static ?string $pluralModelLabel = '訂單';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('order.view_any') ?? false;
    }

    public static function canView($record): bool
    {
        return auth()->user()?->can('order.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false; // create via Admin API only (Idempotency-Key required)
    }

    public static function canEdit($record): bool
    {
        return false; // all mutations go through Service Actions below
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_no')
                    ->label('訂單號')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('member.name')
                    ->label('會員')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Order::STATUS_PENDING => 'warning',
                        Order::STATUS_PAID => 'success',
                        Order::STATUS_SHIPPED => 'info',
                        Order::STATUS_COMPLETED => 'success',
                        Order::STATUS_CANCELLED => 'danger',
                        Order::STATUS_PARTIAL_REFUNDED => 'warning',
                        Order::STATUS_REFUNDED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Order::STATUS_PENDING => '待付款',
                        Order::STATUS_PAID => '已付款',
                        Order::STATUS_SHIPPED => '已出貨',
                        Order::STATUS_COMPLETED => '已完成',
                        Order::STATUS_CANCELLED => '已取消',
                        Order::STATUS_PARTIAL_REFUNDED => '部分退款',
                        Order::STATUS_REFUNDED => '已退款',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('paid_amount')
                    ->label('實付金額')
                    ->numeric()
                    ->prefix('NT$')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('付款方式')
                    ->formatStateUsing(fn (?string $s): string => match ($s) {
                        Order::PAYMENT_METHOD_ECPAY => 'ECPay',
                        Order::PAYMENT_METHOD_OFFLINE => '線下',
                        default => '—',
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('品項')
                    ->counts('items')
                    ->suffix(' 項'),

                Tables\Columns\TextColumn::make('paid_at')
                    ->label('付款時間')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('狀態')
                    ->options([
                        Order::STATUS_PENDING => '待付款',
                        Order::STATUS_PAID => '已付款',
                        Order::STATUS_SHIPPED => '已出貨',
                        Order::STATUS_COMPLETED => '已完成',
                        Order::STATUS_CANCELLED => '已取消',
                        Order::STATUS_PARTIAL_REFUNDED => '部分退款',
                        Order::STATUS_REFUNDED => '已退款',
                    ]),

                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('付款方式')
                    ->options([
                        Order::PAYMENT_METHOD_ECPAY => 'ECPay',
                        Order::PAYMENT_METHOD_OFFLINE => '線下',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('ship')
                    ->label('出貨')
                    ->icon('heroicon-o-truck')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (Order $r) => $r->status === Order::STATUS_PAID
                        && auth()->user()?->can('order.update'))
                    ->action(function (Order $record) {
                        try {
                            app(OrderService::class)->ship($record, auth()->user());
                            Notification::make()->title('已出貨')->success()->send();
                        } catch (InvalidOrderStateTransitionException $e) {
                            Notification::make()->title('無法出貨：'.$e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('complete')
                    ->label('完成')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Order $r) => in_array($r->status, [Order::STATUS_PAID, Order::STATUS_SHIPPED], true)
                        && auth()->user()?->can('order.update'))
                    ->action(function (Order $record) {
                        try {
                            app(OrderService::class)->complete($record, auth()->user());
                            Notification::make()->title('訂單已完成，點數已發放')->success()->send();
                        } catch (InvalidOrderStateTransitionException $e) {
                            Notification::make()->title('無法完成：'.$e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label('取消')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('取消原因')
                            ->required()
                            ->rows(2),
                    ])
                    ->visible(fn (Order $r) => in_array($r->status, [
                        Order::STATUS_PENDING, Order::STATUS_PAID, Order::STATUS_SHIPPED,
                    ], true) && auth()->user()?->can('order.cancel'))
                    ->action(function (Order $record, array $data) {
                        try {
                            app(OrderService::class)->cancel($record, auth()->user(), $data['reason']);
                            Notification::make()->title('訂單已取消')->success()->send();
                        } catch (InvalidOrderStateTransitionException $e) {
                            Notification::make()->title('無法取消：'.$e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('refund')
                    ->label('退款')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('退款金額（NT$）')
                            ->numeric()
                            ->required()
                            ->minValue(1),
                        Forms\Components\Textarea::make('reason')
                            ->label('退款原因')
                            ->required()
                            ->rows(2),
                    ])
                    ->visible(fn (Order $r) => in_array($r->status, [
                        Order::STATUS_COMPLETED, Order::STATUS_PARTIAL_REFUNDED,
                    ], true) && auth()->user()?->can('order.refund'))
                    ->action(function (Order $record, array $data) {
                        try {
                            app(OrderService::class)->refund(
                                $record,
                                (int) $data['amount'],
                                $data['reason'],
                                auth()->user(),
                            );
                            Notification::make()->title('退款完成')->success()->send();
                        } catch (InvalidOrderStateTransitionException $e) {
                            Notification::make()->title('退款失敗：'.$e->getMessage())->danger()->send();
                        } catch (RefundAmountExceedsPaidException $e) {
                            Notification::make()->title('退款金額超過實付：'.$e->getMessage())->danger()->send();
                        }
                    }),
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
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
