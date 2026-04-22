<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 自己控 retry 邏輯,不用 Laravel 內建的 $tries 機制
    public $tries = 1;

    // 指數退避:1m, 5m, 30m, 2h, 12h(對應第 1~5 次重試)
    const RETRY_DELAYS = [60, 300, 1800, 7200, 43200];

    // Circuit breaker:連續失敗超過此數自動停用 subscription
    const CIRCUIT_BREAKER_THRESHOLD = 20;

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::with(['webhookEvent', 'subscription'])
            ->find($this->deliveryId);

        if (! $delivery || $delivery->status === WebhookDelivery::STATUS_SUCCESS) {
            return;
        }

        $sub = $delivery->subscription;
        if (! $sub || ! $sub->canReceive()) {
            // Subscription 可能在派送前被停用或斷路
            return;
        }

        $payload = $delivery->webhookEvent->payload;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // HMAC SHA256 簽章 (timestamp + body 一起簽,防重放)
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $sub->secret);

        try {
            $response = Http::timeout($sub->timeout_seconds)
                ->withHeaders([
                    'Content-Type'        => 'application/json',
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Signature' => "v1={$signature}",
                    'X-Webhook-Event'     => $payload['event'] ?? '',
                    'X-Idempotency-Key'   => $delivery->id,
                ])
                ->withBody($body, 'application/json')
                ->post($sub->url);

            $delivery->attempts++;
            $delivery->http_status = $response->status();
            $delivery->response_body = substr($response->body(), 0, 1000);

            if ($response->successful()) {
                $delivery->status = WebhookDelivery::STATUS_SUCCESS;
                $delivery->delivered_at = now();
                $sub->update(['consecutive_failure_count' => 0]);
            } else {
                $this->scheduleRetry($delivery, $sub);
            }
            $delivery->save();

        } catch (\Throwable $e) {
            Log::warning("Webhook delivery {$delivery->id} failed", [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
            $delivery->attempts++;
            $delivery->error_message = substr($e->getMessage(), 0, 1000);
            $this->scheduleRetry($delivery, $sub);
            $delivery->save();
        }
    }

    private function scheduleRetry(WebhookDelivery $d, WebhookSubscription $sub): void
    {
        if ($d->attempts >= $sub->max_retries) {
            $d->status = WebhookDelivery::STATUS_FAILED;
            $this->tripCircuitBreakerIfNeeded($sub);
            return;
        }

        $delay = self::RETRY_DELAYS[$d->attempts - 1] ?? 43200;

        $d->status = WebhookDelivery::STATUS_RETRYING;
        $d->next_retry_at = now()->addSeconds($delay);

        self::dispatch($d->id)
            ->onQueue('webhooks')
            ->delay(now()->addSeconds($delay));
    }

    private function tripCircuitBreakerIfNeeded(WebhookSubscription $sub): void
    {
        $sub->increment('consecutive_failure_count');

        if ($sub->consecutive_failure_count >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $sub->update(['is_circuit_broken' => true]);
            Log::alert('Webhook subscription circuit broken', [
                'subscription_id' => $sub->id,
                'name'            => $sub->name,
                'consecutive'     => $sub->consecutive_failure_count,
            ]);
            // TODO(Phase 2): 發通知給 admin(email / Filament notification)
        }
    }
}
