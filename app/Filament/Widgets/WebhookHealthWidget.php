<?php

namespace App\Filament\Widgets;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class WebhookHealthWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $since = now()->subDay();

        $last24 = WebhookDelivery::where('created_at', '>=', $since);
        $success24 = (clone $last24)->where('status', WebhookDelivery::STATUS_SUCCESS)->count();
        $failed24  = (clone $last24)->where('status', WebhookDelivery::STATUS_FAILED)->count();
        $total24   = (clone $last24)->whereIn('status', [
            WebhookDelivery::STATUS_SUCCESS,
            WebhookDelivery::STATUS_FAILED,
        ])->count();

        $successRate = $total24 > 0 ? round(($success24 / $total24) * 100, 1) : 100;

        $circuitBroken = WebhookSubscription::where('is_circuit_broken', true)->count();

        $queueDepth = WebhookDelivery::whereIn('status', [
            WebhookDelivery::STATUS_PENDING,
            WebhookDelivery::STATUS_RETRYING,
        ])->count();

        return [
            Stat::make('最近 24h 成功率', "{$successRate}%")
                ->description("{$success24} 成功 / {$failed24} 失敗")
                ->descriptionIcon($successRate >= 95 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($this->rateColor($successRate)),

            Stat::make('最近 24h 失敗數', $failed24)
                ->description($failed24 === 0 ? '一切正常' : '需要關注')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failed24 === 0 ? 'success' : 'danger'),

            Stat::make('斷路中訂閱', $circuitBroken)
                ->description($circuitBroken === 0 ? '全部健康' : '需手動解除')
                ->descriptionIcon($circuitBroken === 0 ? 'heroicon-m-shield-check' : 'heroicon-m-shield-exclamation')
                ->color($circuitBroken === 0 ? 'success' : 'danger'),

            Stat::make('Queue 深度', $queueDepth)
                ->description('pending + retrying')
                ->descriptionIcon('heroicon-m-queue-list')
                ->color($queueDepth > 100 ? 'warning' : 'gray'),
        ];
    }

    private function rateColor(float $rate): string
    {
        if ($rate >= 99) return 'success';
        if ($rate >= 95) return 'warning';
        return 'danger';
    }

    public static function canView(): bool
    {
        // 只有 Webhooks 相關的 admin 才看得到（目前沒權限系統,一律顯示）
        return true;
    }
}
