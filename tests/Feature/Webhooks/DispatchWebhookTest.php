<?php

namespace Tests\Feature\Webhooks;

use App\Events\Webhooks\MemberCreated;
use App\Jobs\SendWebhookJob;
use App\Models\Member;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DispatchWebhook listener 的 feature test。
 *
 * 只驗證「event 觸發 → Event/Delivery 正確建立 → Job 入 queue」這條路徑,
 * 不實際呼叫下游 HTTP (那是 SendWebhookJob 的責任,另檔測試)。
 */
class DispatchWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveMember(string $email = 'wh-test@example.com'): Member
    {
        return Member::create([
            'uuid'              => (string) Str::uuid(),
            'name'              => 'Webhook Test',
            'email'             => $email,
            'password'          => 'secret',
            'status'            => Member::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function makeSubscription(array $events = ['member.created'], array $overrides = []): WebhookSubscription
    {
        return WebhookSubscription::create(array_merge([
            'name'              => 'Test Subscriber',
            'url'               => 'https://example.test/webhook',
            'events'            => $events,
            'secret'            => WebhookSubscription::generateSecret(),
            'is_active'         => true,
            'is_circuit_broken' => false,
            'max_retries'       => 5,
            'timeout_seconds'   => 10,
        ], $overrides));
    }

    // ---- 基本 flow ----

    public function test_member_created_event_writes_event_and_delivery(): void
    {
        Bus::fake();
        $sub = $this->makeSubscription();
        $member = $this->makeActiveMember();

        event(new MemberCreated($member));

        // Event 被存
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'event_type' => 'member.created',
        ]);

        // Delivery 在 pending 狀態
        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->assertDatabaseHas('webhook_deliveries', [
            'subscription_id' => $sub->id,
            'status'          => WebhookDelivery::STATUS_PENDING,
            'attempts'        => 0,
        ]);

        // Job 入 queue(我們用 Bus::fake 攔下,不實際送)
        Bus::assertDispatched(SendWebhookJob::class);
    }

    public function test_payload_includes_sequence_number(): void
    {
        Bus::fake();
        $this->makeSubscription();

        $m1 = $this->makeActiveMember('seq-a@example.com');
        event(new MemberCreated($m1));

        $m2 = $this->makeActiveMember('seq-b@example.com');
        event(new MemberCreated($m2));

        $events = WebhookEvent::orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame($events[0]->id, $events[0]->payload['sequence']);
        $this->assertSame($events[1]->id, $events[1]->payload['sequence']);
        $this->assertGreaterThan(
            $events[0]->payload['sequence'],
            $events[1]->payload['sequence'],
            'sequence 要單調遞增',
        );
    }

    // ---- Subscription 過濾 ----

    public function test_inactive_subscription_not_dispatched(): void
    {
        Bus::fake();
        $this->makeSubscription(['member.created'], ['is_active' => false]);

        event(new MemberCreated($this->makeActiveMember()));

        // Event 仍會存(供稽核 / 日後補送)
        $this->assertDatabaseCount('webhook_events', 1);
        // 但沒 delivery
        $this->assertDatabaseCount('webhook_deliveries', 0);
        Bus::assertNothingDispatched();
    }

    public function test_circuit_broken_subscription_not_dispatched(): void
    {
        Bus::fake();
        $this->makeSubscription(['member.created'], ['is_circuit_broken' => true]);

        event(new MemberCreated($this->makeActiveMember()));

        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_subscription_not_subscribed_to_event_not_dispatched(): void
    {
        Bus::fake();
        // 訂閱 wallet.changed,不是 member.created
        $this->makeSubscription(['wallet.changed']);

        event(new MemberCreated($this->makeActiveMember()));

        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_multiple_subscribers_each_get_own_delivery(): void
    {
        Bus::fake();
        $sub1 = $this->makeSubscription(['member.created'], ['name' => 'Sub A']);
        $sub2 = $this->makeSubscription(['member.created'], ['name' => 'Sub B']);

        event(new MemberCreated($this->makeActiveMember()));

        $this->assertDatabaseCount('webhook_deliveries', 2);
        $this->assertDatabaseHas('webhook_deliveries', ['subscription_id' => $sub1->id]);
        $this->assertDatabaseHas('webhook_deliveries', ['subscription_id' => $sub2->id]);

        Bus::assertDispatchedTimes(SendWebhookJob::class, 2);
    }

    // ---- Payload size guard ----

    public function test_payload_over_512kb_is_not_dispatched(): void
    {
        Bus::fake();
        Log::spy();
        $this->makeSubscription();

        // 做一個會超過 512KB 的 event(匿名類別繞過 $listen 映射,直接餵 listener)
        $fakeEvent = new class {
            public function toWebhookPayload(): array
            {
                return [
                    'event'       => 'member.created',
                    'occurred_at' => now()->toIso8601String(),
                    'data'        => ['bloat' => str_repeat('A', 600 * 1024)],
                ];
            }
        };

        // 直接 invoke listener (event() 對匿名 class 找不到 $listen 對應)
        app(\App\Listeners\DispatchWebhook::class)->handle($fakeEvent);

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);
        Log::shouldHaveReceived('error')->once();
    }

    // ---- 多事件類型 ----

    public function test_member_verified_email_event_fires_matching_webhook(): void
    {
        Bus::fake();
        $this->makeSubscription(['member.email_verified']);

        event(new \App\Events\Webhooks\MemberVerifiedEmail($this->makeActiveMember()));

        $this->assertDatabaseHas('webhook_events', [
            'event_type' => 'member.email_verified',
        ]);
        $this->assertDatabaseCount('webhook_deliveries', 1);
    }

    public function test_member_logged_in_event_carries_method(): void
    {
        Bus::fake();
        $this->makeSubscription(['member.logged_in']);

        $member = $this->makeActiveMember();
        event(new \App\Events\Webhooks\MemberLoggedIn($member, 'google'));

        $event = \App\Models\WebhookEvent::where('event_type', 'member.logged_in')->first();
        $this->assertNotNull($event);
        $this->assertSame('google', $event->payload['data']['method']);
    }

    public function test_member_updated_event_carries_diff_via_me_endpoint(): void
    {
        Bus::fake();
        $this->makeSubscription(['member.updated']);

        $member = $this->makeActiveMember();
        \Laravel\Sanctum\Sanctum::actingAs($member, [], 'member');

        // 初始 nickname null,改成「小明」;phone 保持空 → 只有 nickname 變動
        $response = $this->putJson('/api/v1/me', [
            'nickname' => '小明',
        ]);

        $response->assertStatus(200);

        $event = \App\Models\WebhookEvent::where('event_type', 'member.updated')->first();
        $this->assertNotNull($event, 'member.updated 事件應該被建立');
        $this->assertSame($member->uuid, $event->payload['data']['uuid']);

        $changes = $event->payload['data']['changes'];
        $this->assertArrayHasKey('nickname', $changes);
        $this->assertNull($changes['nickname']['from']);
        $this->assertSame('小明', $changes['nickname']['to']);
    }

    public function test_member_deleted_event_fires_when_destroying_self(): void
    {
        Bus::fake();
        $this->makeSubscription(['member.deleted']);

        $member = $this->makeActiveMember();
        \Laravel\Sanctum\Sanctum::actingAs($member, [], 'member');

        $response = $this->deleteJson('/api/v1/me');
        $response->assertStatus(200);

        // soft delete 完成,deleted_at 已寫
        $this->assertSoftDeleted('members', ['id' => $member->id]);

        $event = \App\Models\WebhookEvent::where('event_type', 'member.deleted')->first();
        $this->assertNotNull($event, 'member.deleted 事件應該被建立');
        $this->assertSame($member->uuid, $event->payload['data']['uuid']);
        $this->assertSame($member->email, $event->payload['data']['email']);
        $this->assertNotNull(
            $event->payload['data']['deleted_at'],
            'payload 應該帶 deleted_at 時戳,讓下游能知道何時刪的',
        );
    }

    public function test_member_updated_event_not_fired_when_no_change(): void
    {
        Bus::fake();
        $this->makeSubscription(['member.updated']);

        $member = $this->makeActiveMember();
        \Laravel\Sanctum\Sanctum::actingAs($member, [], 'member');

        // 送跟目前一模一樣的值 — getDirty 應該是空
        $this->putJson('/api/v1/me', [
            'name' => $member->name,
        ])->assertStatus(200);

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_oauth_bound_event_carries_provider_metadata(): void
    {
        Bus::fake();
        $this->makeSubscription(['oauth.bound']);

        $member = $this->makeActiveMember();
        $sns = \App\Models\MemberSns::create([
            'member_id'        => $member->id,
            'provider'         => 'github',
            'provider_user_id' => 'gh-99',
        ]);

        event(new \App\Events\Webhooks\OAuthBound($member, $sns, isNewAccount: false));

        $event = \App\Models\WebhookEvent::where('event_type', 'oauth.bound')->first();
        $this->assertNotNull($event);
        $this->assertSame('github', $event->payload['data']['provider']);
        $this->assertSame('gh-99', $event->payload['data']['provider_user_id']);
        $this->assertFalse($event->payload['data']['is_new_account']);
    }

    public function test_subscription_filters_events_by_type(): void
    {
        Bus::fake();
        // 只訂閱 member.logged_in,不訂 member.created
        $this->makeSubscription(['member.logged_in']);

        event(new \App\Events\Webhooks\MemberCreated($this->makeActiveMember()));

        // webhook_events 仍會寫(稽核快照)
        $this->assertDatabaseCount('webhook_events', 1);
        // 但 deliveries 應該 0,因為沒有訂閱者訂 member.created
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    // ---- 整合:真的跑 Register flow 會觸發事件 ----

    public function test_register_endpoint_triggers_member_created_webhook(): void
    {
        Bus::fake();
        $this->makeSubscription();

        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Integration Test',
            'email'                 => 'integration@example.com',
            'password'              => 'Test1234',
            'password_confirmation' => 'Test1234',
            'agree_terms'           => true,
        ])->assertStatus(201);

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'event_type' => 'member.created',
        ]);
        $this->assertDatabaseCount('webhook_deliveries', 1);
    }
}
