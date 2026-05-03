<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([

            Section::make('訂單資訊')->schema([
                TextEntry::make('order_no')->label('訂單號')->copyable()->fontFamily('mono'),
                TextEntry::make('status')
                    ->label('狀態')
                    ->badge()
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
                TextEntry::make('member.name')->label('會員'),
                TextEntry::make('member.email')->label('Email'),
                TextEntry::make('payment_method')->label('付款方式')
                    ->formatStateUsing(fn (?string $s): string => match ($s) {
                        'ecpay' => 'ECPay',
                        'offline' => '線下',
                        default => '—',
                    }),
                TextEntry::make('paid_at')->label('付款時間')->dateTime('Y-m-d H:i'),
                TextEntry::make('created_at')->label('建立時間')->dateTime('Y-m-d H:i'),
            ])->columns(3),

            Section::make('金額明細')->schema([
                TextEntry::make('subtotal')->label('商品小計')->numeric()->prefix('NT$'),
                TextEntry::make('discount_total')->label('折扣')->numeric()->prefix('-NT$'),
                TextEntry::make('paid_amount')->label('實付金額')->numeric()->prefix('NT$'),
                TextEntry::make('refund_amount')->label('已退款')->numeric()->prefix('NT$'),
                TextEntry::make('points_earned')->label('累積點數'),
                TextEntry::make('points_refunded')->label('退還點數'),
            ])->columns(3),

            Section::make('商品明細')->schema([
                RepeatableEntry::make('items')->schema([
                    TextEntry::make('product_sku')->label('SKU'),
                    TextEntry::make('product_name')->label('商品名稱'),
                    TextEntry::make('unit_price')->label('單價')->numeric()->prefix('NT$'),
                    TextEntry::make('quantity')->label('數量'),
                    TextEntry::make('subtotal')->label('小計')->numeric()->prefix('NT$'),
                ])->columns(5),
            ]),

            Section::make('收貨地址')->schema([
                TextEntry::make('shippingAddress.recipient_name')->label('收件人'),
                TextEntry::make('shippingAddress.phone')->label('電話'),
                TextEntry::make('shippingAddress.postal_code')->label('郵遞區號'),
                TextEntry::make('shippingAddress.city')->label('縣市'),
                TextEntry::make('shippingAddress.district')->label('區'),
                TextEntry::make('shippingAddress.address_line')->label('地址'),
            ])->columns(3),

            Section::make('狀態歷程')->schema([
                RepeatableEntry::make('statusHistories')->schema([
                    TextEntry::make('from_status')->label('從')->placeholder('—'),
                    TextEntry::make('to_status')->label('至'),
                    TextEntry::make('reason')->label('原因')->placeholder('—'),
                    TextEntry::make('actor.name')->label('操作者')->placeholder('system'),
                    TextEntry::make('created_at')->label('時間')->dateTime('Y-m-d H:i'),
                ])->columns(5),
            ]),

        ]);
    }
}
