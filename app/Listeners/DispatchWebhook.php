<?php

namespace App\Listeners;

use App\Jobs\SendWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent as WebhookEventModel;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchWebhook
{
    const PAYLOAD_MAX_BYTES = 512 * 1024; // 512KB

    public function handle(object $event): void
    {
        // 所有 webhook event 類別都需要有 toWebhookPayload() 方法
        if (! method_exists($event, 'toWebhookPayload')) {
            return;
        }

        $payload = $event->toWebhookPayload();

        // Guard: payload 大小硬上限
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > self::PAYLOAD_MAX_BYTES) {
            Log::error('Webhook payload exceeds 512KB limit', [
                'event' => $payload['event'] ?? 'unknown',
                'size'  => strlen($encoded),
            ]);
            return;
        }

        // Transaction + afterCommit:避免 queue 搶先撿到不存在的 delivery
        DB::transaction(function () use ($payload) {
            $webhookEvent = WebhookEventModel::create([
                'event_type'  => $payload['event'],
                'payload'     => $payload, // 先存,下一步回填 sequence
                'occurred_at' => $payload['occurred_at'],
                'created_at'  => now(),
            ]);

            // 回填 sequence = event.id(單調遞增,接收端可用於 out-of-order 偵測)
            $webhookEvent->payload = array_merge($payload, [
                'sequence' => $webhookEvent->id,
            ]);
            $webhookEvent->save();

            // 找出訂閱此事件、且 active + 未斷路的 subscription
            $subs = WebhookSubscription::where('is_active', true)
                ->where('is_circuit_broken', false)
                ->whereJsonContains('events', $payload['event'])
                ->get();

            foreach ($subs as $sub) {
                $delivery = WebhookDelivery::create([
                    'webhook_event_id' => $webhookEvent->id,
                    'subscription_id'  => $sub->id,
                    'status'           => WebhookDelivery::STATUS_PENDING,
                    'created_at'       => now(),
                ]);

                SendWebhookJob::dispatch($delivery->id)
                    ->onQueue('webhooks')
                    ->afterCommit(); // 確保 transaction commit 後才入 queue
            }
        });
    }
}
