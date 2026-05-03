<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderSettings;
use App\Services\Order\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scan for pending orders that have exceeded the timeout and cancel them.
 *
 * Scheduled every 5 minutes in Console\Kernel (per ORDER_INTEGRATION_PLAN §8b).
 * Each cancelled order fires the OrderCancelled webhook event so downstream
 * systems (e.g., email/SMS notifications — Phase 2.4) are notified.
 *
 * Timeout duration is read from order_settings.pending_timeout_minutes
 * so it can be changed via Filament admin UI without a code deploy.
 */
class CancelPendingOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(OrderService $orderService): void
    {
        $timeoutMinutes = OrderSettings::current()->pending_timeout_minutes;
        $cutoff = now()->subMinutes($timeoutMinutes);

        $timedOut = Order::where('status', Order::STATUS_PENDING)
            ->where('created_at', '<=', $cutoff)
            ->get();

        if ($timedOut->isEmpty()) {
            return;
        }

        Log::info('CancelPendingOrdersJob: cancelling timed-out orders', [
            'count' => $timedOut->count(),
            'cutoff' => $cutoff->toIso8601String(),
        ]);

        foreach ($timedOut as $order) {
            try {
                $orderService->cancel($order, null, 'pending_timeout');
            } catch (\Throwable $e) {
                // Log and continue — one failure must not block the rest
                Log::error('CancelPendingOrdersJob: failed to cancel order', [
                    'order_no' => $order->order_no,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
