<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\SendWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendWebhookJobTest extends TestCase
{
    use RefreshDatabase;

    // ---- helpers -----------------------------------------------------

    private function makeSubscription(array $overrides = []): WebhookSubscription
    {
        return WebhookSubscription::create(array_merge([
            'name'                      => 'Receiver',
            'url'                       => 'https://receiver.test/webhook',
            'events'                    => ['member.created'],
            'secret'                    => 'test-secret-abcdef',
            'is_active'                 => true,
            'is_circuit_broken'         => false,
            'consecutive_failure_count' => 0,
            'max_retries'               => 5,
            'timeout_seconds'           => 10,
        ], $overrides));
    }

    private function makeDelivery(
        WebhookSubscription $sub,
        array $payloadOverride = [],
        array $deliveryOverride = [],
    ): WebhookDelivery {
        $event = WebhookEvent::create([
            'event_type'  => 'member.created',
            'payload'     => array_merge([
                'event'       => 'member.created',
                'occurred_at' => now()->toIso8601String(),
                'sequence'    => 1,
                'data'        => ['uuid' => 'abc-123'],
            ], $payloadOverride),
            'occurred_at' => now(),
            'created_at'  => now(),
        ]);

        return WebhookDelivery::create(array_merge([
            'webhook_event_id' => $event->id,
            'subscription_id'  => $sub->id,
            'status'           => WebhookDelivery::STATUS_PENDING,
            'attempts'         => 0,
            'created_at'       => now(),
        ], $deliveryOverride));
    }

    private function runJob(WebhookDelivery $d): void
    {
        (new SendWebhookJob($d->id))->handle();
    }

    // ---- happy path --------------------------------------------------

    public function test_successful_delivery_updates_status_and_zeroes_failures(): void
    {
        Http::fake(['*' => Http::response('OK', 200)]);
        $sub = $this->makeSubscription(['consecutive_failure_count' => 5]);
        $d = $this->makeDelivery($sub);

        $this->runJob($d);

        $d->refresh();
        $this->assertSame(WebhookDelivery::STATUS_SUCCESS, $d->status);
        $this->assertSame(1, $d->attempts);
        $this->assertSame(200, $d->http_status);
        $this->assertNotNull($d->delivered_at);
        $this->assertSame(0, $sub->fresh()->consecutive_failure_count,
            '成功時應歸零 consecutive_failure_count');
    }

    public function test_request_carries_expected_hmac_and_idempotency_headers(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $sub = $this->makeSubscription(['secret' => 'known-secret']);
        $d = $this->makeDelivery($sub);

        $this->runJob($d);

        Http::assertSent(function (Request $request) use ($d) {
            $body = $request->body();
            $ts = $request->header('X-Webhook-Timestamp')[0] ?? null;
            $sig = $request->header('X-Webhook-Signature')[0] ?? '';
            $idempotency = $request->header('X-Idempotency-Key')[0] ?? null;
            $event = $request->header('X-Webhook-Event')[0] ?? null;

            $this->assertNotNull($ts, 'X-Webhook-Timestamp 必帶');
            $this->assertSame("v1=" . hash_hmac('sha256', $ts . '.' . $body, 'known-secret'), $sig);
            $this->assertSame((string) $d->id, $idempotency);
            $this->assertSame('member.created', $event);
            return true;
        });
    }

    // ---- retry + backoff ---------------------------------------------

    public function test_failure_increments_attempts_and_schedules_retry(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('err', 500)]);
        $sub = $this->makeSubscription();
        $d = $this->makeDelivery($sub);

        $this->runJob($d);

        $d->refresh();
        $this->assertSame(WebhookDelivery::STATUS_RETRYING, $d->status);
        $this->assertSame(1, $d->attempts);
        $this->assertSame(500, $d->http_status);
        $this->assertNotNull($d->next_retry_at);

        Queue::assertPushed(SendWebhookJob::class, function ($job) use ($d) {
            return $job->deliveryId === $d->id;
        });
    }

    public function test_exponential_backoff_delays(): void
    {
        $expected = [60, 300, 1800, 7200, 43200];
        $this->assertSame($expected, SendWebhookJob::RETRY_DELAYS);
    }

    public function test_max_retries_reached_marks_failed(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('err', 500)]);
        $sub = $this->makeSubscription(['max_retries' => 3]);
        // 已經試過 2 次,這次第 3 次失敗就 failed
        $d = $this->makeDelivery($sub, [], ['attempts' => 2, 'status' => WebhookDelivery::STATUS_RETRYING]);

        $this->runJob($d);

        $d->refresh();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $d->status);
        $this->assertSame(3, $d->attempts);
        // 失敗時不再排下次
        Queue::assertNothingPushed();
    }

    public function test_network_exception_is_caught_and_retried(): void
    {
        Queue::fake();
        Http::fake(function () {
            throw new \RuntimeException('Connection timeout');
        });
        $sub = $this->makeSubscription();
        $d = $this->makeDelivery($sub);

        $this->runJob($d);

        $d->refresh();
        $this->assertSame(WebhookDelivery::STATUS_RETRYING, $d->status);
        $this->assertSame(1, $d->attempts);
        $this->assertNotNull($d->error_message);
        $this->assertStringContainsString('Connection timeout', $d->error_message);
    }

    // ---- circuit breaker ---------------------------------------------

    public function test_consecutive_failures_trip_circuit_breaker_at_threshold(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('err', 500)]);
        // 已經連續失敗 19 次,這次 failed 就達 20 觸發斷路
        $sub = $this->makeSubscription([
            'consecutive_failure_count' => 19,
            'max_retries'               => 1,
        ]);
        $d = $this->makeDelivery($sub, [], ['attempts' => 1]);

        $this->runJob($d);

        $d->refresh();
        $sub->refresh();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $d->status);
        $this->assertSame(20, $sub->consecutive_failure_count);
        $this->assertTrue($sub->is_circuit_broken, '達到 20 次應斷路');
    }

    public function test_circuit_breaker_not_tripped_below_threshold(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('err', 500)]);
        $sub = $this->makeSubscription([
            'consecutive_failure_count' => 5,
            'max_retries'               => 1,
        ]);
        $d = $this->makeDelivery($sub, [], ['attempts' => 1]);

        $this->runJob($d);

        $sub->refresh();
        $this->assertSame(6, $sub->consecutive_failure_count);
        $this->assertFalse($sub->is_circuit_broken);
    }

    // ---- guards ------------------------------------------------------

    public function test_skips_delivery_when_subscription_is_deactivated(): void
    {
        Http::fake();
        $sub = $this->makeSubscription(['is_active' => false]);
        $d = $this->makeDelivery($sub);

        $this->runJob($d);

        $d->refresh();
        $this->assertSame(WebhookDelivery::STATUS_PENDING, $d->status,
            'deactivated 時 job 應直接 return,不動 status');
        Http::assertNothingSent();
    }

    public function test_skips_delivery_when_subscription_is_circuit_broken(): void
    {
        Http::fake();
        $sub = $this->makeSubscription(['is_circuit_broken' => true]);
        $d = $this->makeDelivery($sub);

        $this->runJob($d);

        Http::assertNothingSent();
    }

    public function test_skips_already_successful_delivery(): void
    {
        Http::fake();
        $sub = $this->makeSubscription();
        $d = $this->makeDelivery($sub, [], [
            'status'       => WebhookDelivery::STATUS_SUCCESS,
            'delivered_at' => now(),
        ]);

        $this->runJob($d);

        Http::assertNothingSent();
    }
}
