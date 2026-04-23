<?php

namespace Tests\Feature\Api\V1\Me;

use App\Models\Member;
use App\Models\MemberSns;
use App\Models\WebhookEvent;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnbindSnsTest extends TestCase
{
    use RefreshDatabase;

    private function makeMemberWithSns(
        array $snsProviders,
        bool $emailVerified = true,
    ): Member {
        $member = Member::create([
            'uuid'              => (string) Str::uuid(),
            'name'              => 'SNS Test',
            'email'             => 'sns-test-' . Str::random(6) . '@example.com',
            'password'          => 'Test1234',
            'status'            => Member::STATUS_ACTIVE,
            'email_verified_at' => $emailVerified ? now() : null,
        ]);

        foreach ($snsProviders as $provider) {
            MemberSns::create([
                'member_id'        => $member->id,
                'provider'         => $provider,
                'provider_user_id' => "{$provider}-uid-" . Str::random(6),
            ]);
        }

        return $member;
    }

    // ---- happy paths ----

    public function test_unbinds_a_non_last_sns_successfully(): void
    {
        Bus::fake();
        $member = $this->makeMemberWithSns(['google', 'github']);
        Sanctum::actingAs($member, [], 'member');

        $response = $this->deleteJson('/api/v1/me/sns/google');

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'code' => 'S200', 'data' => ['provider' => 'google']]);

        $this->assertDatabaseMissing('member_sns', [
            'member_id' => $member->id,
            'provider'  => 'google',
        ]);
        $this->assertDatabaseHas('member_sns', [
            'member_id' => $member->id,
            'provider'  => 'github',
        ]);
    }

    public function test_unbinds_last_sns_when_email_is_verified(): void
    {
        Bus::fake();
        // email 已驗證 → 即使是最後一個 SNS 也允許(使用者可以走忘記密碼救回)
        $member = $this->makeMemberWithSns(['google'], emailVerified: true);
        Sanctum::actingAs($member, [], 'member');

        $this->deleteJson('/api/v1/me/sns/google')
             ->assertStatus(200);

        $this->assertSame(0, $member->sns()->count());
    }

    // ---- blocked ----

    public function test_rejects_unbinding_last_sns_when_email_not_verified(): void
    {
        Bus::fake();
        $member = $this->makeMemberWithSns(['google'], emailVerified: false);
        Sanctum::actingAs($member, [], 'member');

        $response = $this->deleteJson('/api/v1/me/sns/google');

        $response->assertStatus(409)
                 ->assertJson(['success' => false, 'code' => 'A012'])
                 ->assertJson(['errors' => ['provider' => ['google']]]);

        // SNS 仍在
        $this->assertDatabaseHas('member_sns', [
            'member_id' => $member->id,
            'provider'  => 'google',
        ]);
    }

    public function test_returns_404_when_provider_not_bound(): void
    {
        Bus::fake();
        $member = $this->makeMemberWithSns(['google']);
        Sanctum::actingAs($member, [], 'member');

        $response = $this->deleteJson('/api/v1/me/sns/github');

        $response->assertStatus(404)
                 ->assertJson(['code' => 'N001']);
    }

    public function test_requires_auth(): void
    {
        $this->deleteJson('/api/v1/me/sns/google')
             ->assertStatus(401)
             ->assertJson(['code' => 'A001']);
    }

    // ---- webhook integration ----

    public function test_unbind_fires_oauth_unbound_webhook(): void
    {
        Bus::fake();
        WebhookSubscription::create([
            'name'              => 'receiver',
            'url'               => 'https://example.test/hook',
            'events'            => ['oauth.unbound'],
            'secret'            => WebhookSubscription::generateSecret(),
            'is_active'         => true,
            'is_circuit_broken' => false,
            'max_retries'       => 5,
            'timeout_seconds'   => 10,
        ]);

        $member = $this->makeMemberWithSns(['google', 'github']);
        $sns = $member->sns()->where('provider', 'google')->first();
        $expectedProviderUid = $sns->provider_user_id;

        Sanctum::actingAs($member, [], 'member');
        $this->deleteJson('/api/v1/me/sns/google')->assertStatus(200);

        $event = WebhookEvent::where('event_type', 'oauth.unbound')->first();
        $this->assertNotNull($event);
        $this->assertSame($member->uuid, $event->payload['data']['member_uuid']);
        $this->assertSame('google', $event->payload['data']['provider']);
        $this->assertSame($expectedProviderUid, $event->payload['data']['provider_user_id']);
    }
}
