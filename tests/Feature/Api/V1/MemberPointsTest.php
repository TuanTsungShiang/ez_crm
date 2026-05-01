<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiCode;
use App\Models\Member;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\Points\PointService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        return $user;
    }

    private function viewerUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('viewer');
        return $user;
    }

    private function customerSupportUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('customer_support');
        return $user;
    }

    private function makeMember(int $points = 0): Member
    {
        return Member::create([
            'uuid'   => (string) Str::uuid(),
            'name'   => 'Test Member',
            'email'  => 'member' . uniqid() . '@example.com',
            'status' => Member::STATUS_ACTIVE,
            'points' => $points,
        ]);
    }

    private function idempotencyKey(): string
    {
        return (string) Str::uuid();
    }

    // ── GET /members/{uuid}/points ────────────────────────────────────────

    public function test_show_returns_balance_and_transactions(): void
    {
        $admin  = $this->adminUser();
        $member = $this->makeMember(500);

        app(PointService::class)->adjust($member, 100, '加點', 'earn', $this->idempotencyKey());
        app(PointService::class)->adjust($member, -50, '扣點', 'spend', $this->idempotencyKey());

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/members/{$member->uuid}/points");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('code', ApiCode::OK)
            ->assertJsonPath('data.member_uuid', $member->uuid)
            ->assertJsonPath('data.balance', 550)
            ->assertJsonStructure([
                'data' => [
                    'member_uuid',
                    'balance',
                    'transactions' => [
                        'data'         => [['id', 'amount', 'balance_after', 'type', 'reason', 'actor', 'created_at']],
                        'current_page',
                        'last_page',
                        'total',
                    ],
                ],
            ]);

        $this->assertSame(2, $response->json('data.transactions.total'));
    }

    public function test_show_returns_zero_balance_when_no_transactions(): void
    {
        $member = $this->makeMember(0);

        $response = $this->actingAs($this->adminUser())
            ->getJson("/api/v1/members/{$member->uuid}/points");

        $response->assertOk()
            ->assertJsonPath('data.balance', 0)
            ->assertJsonPath('data.transactions.total', 0);
    }

    public function test_show_requires_authentication(): void
    {
        $member = $this->makeMember();

        $this->getJson("/api/v1/members/{$member->uuid}/points")
            ->assertUnauthorized();
    }

    public function test_show_requires_points_view_permission(): void
    {
        $member = $this->makeMember();
        // viewer has points.view, should pass
        $response = $this->actingAs($this->viewerUser())
            ->getJson("/api/v1/members/{$member->uuid}/points");

        $response->assertOk();
    }

    public function test_show_forbidden_for_user_without_points_permission(): void
    {
        $member = $this->makeMember();
        $user   = User::factory()->create(); // no role assigned

        $this->actingAs($user)
            ->getJson("/api/v1/members/{$member->uuid}/points")
            ->assertForbidden();
    }

    public function test_show_returns_404_for_nonexistent_member(): void
    {
        $this->actingAs($this->adminUser())
            ->getJson('/api/v1/members/00000000-0000-0000-0000-000000000000/points')
            ->assertNotFound();
    }

    // ── POST /members/{uuid}/points/adjust ────────────────────────────────

    public function test_adjust_adds_points_and_returns_balance(): void
    {
        $admin  = $this->adminUser();
        $member = $this->makeMember(100);

        $response = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => $this->idempotencyKey()])
            ->postJson("/api/v1/members/{$member->uuid}/points/adjust", [
                'amount' => 50,
                'reason' => '加點測試',
                'type'   => 'earn',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.balance_before', 100)
            ->assertJsonPath('data.balance_after', 150)
            ->assertJsonPath('data.amount', 50)
            ->assertJsonPath('data.replayed', false);

        $member->refresh();
        $this->assertSame(150, $member->points);
    }

    public function test_adjust_deducts_points(): void
    {
        $member = $this->makeMember(200);

        $response = $this->actingAs($this->adminUser())
            ->withHeaders(['Idempotency-Key' => $this->idempotencyKey()])
            ->postJson("/api/v1/members/{$member->uuid}/points/adjust", [
                'amount' => -80,
                'reason' => '扣點測試',
                'type'   => 'spend',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.balance_after', 120)
            ->assertJsonPath('data.amount', -80);
    }

    public function test_adjust_returns_b001_when_insufficient_points(): void
    {
        $member = $this->makeMember(50);

        $response = $this->actingAs($this->adminUser())
            ->withHeaders(['Idempotency-Key' => $this->idempotencyKey()])
            ->postJson("/api/v1/members/{$member->uuid}/points/adjust", [
                'amount' => -100,
                'reason' => '超額扣點',
                'type'   => 'spend',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', ApiCode::INSUFFICIENT_POINTS);

        $member->refresh();
        $this->assertSame(50, $member->points, 'balance must be unchanged after rollback');
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_adjust_replay_returns_b002_and_original_transaction(): void
    {
        $member = $this->makeMember(100);
        $key    = $this->idempotencyKey();

        $first = $this->actingAs($this->adminUser())
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/members/{$member->uuid}/points/adjust", [
                'amount' => 50,
                'reason' => '首次',
                'type'   => 'earn',
            ]);
        $first->assertOk();

        $replay = $this->actingAs($this->adminUser())
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/members/{$member->uuid}/points/adjust", [
                'amount' => 50,
                'reason' => '重複送',
                'type'   => 'earn',
            ]);

        $replay->assertOk()
            ->assertJsonPath('code', ApiCode::IDEMPOTENCY_REPLAY)
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.transaction_id', $first->json('data.transaction_id'));

        $this->assertSame(1, PointTransaction::count());

        $member->refresh();
        $this->assertSame(150, $member->points, 'points must increment only once');
    }

    public function test_adjust_requires_idempotency_key_header(): void
    {
        $member = $this->makeMember(100);

        $this->actingAs($this->adminUser())
            ->postJson("/api/v1/members/{$member->uuid}/points/adjust", [
                'amount' => 10,
                'reason' => '無冪等 key',
                'type'   => 'earn',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', ApiCode::MISSING_FIELD);
    }

    public function test_adjust_rejects_zero_amount(): void
    {
        $member = $this->makeMember(100);

        $this->actingAs($this->adminUser())
            ->withHeaders(['Idempotency-Key' => $this->idempotencyKey()])
            ->postJson("/api/v1/members/{$member->uuid}/points/adjust", [
                'amount' => 0,
                'reason' => '零點',
                'type'   => 'earn',
            ])
            ->assertStatus(422);
    }

    public function test_adjust_rejects_invalid_type(): void
    {
        $member = $this->makeMember(100);

        $this->actingAs($this->adminUser())
            ->withHeaders(['Idempotency-Key' => $this->idempotencyKey()])
            ->postJson("/api/v1/members/{$member->uuid}/points/adjust", [
                'amount' => 10,
                'reason' => '壞 type',
                'type'   => 'expire', // expire 不開給 API
            ])
            ->assertStatus(422);
    }

    public function test_adjust_requires_points_manage_permission(): void
    {
        $member = $this->makeMember(100);

        // customer_support has only points.view, NOT points.manage
        $this->actingAs($this->customerSupportUser())
            ->withHeaders(['Idempotency-Key' => $this->idempotencyKey()])
            ->postJson("/api/v1/members/{$member->uuid}/points/adjust", [
                'amount' => 10,
                'reason' => '無權限測試',
                'type'   => 'earn',
            ])
            ->assertForbidden();
    }

    public function test_adjust_marketing_user_can_manage_points(): void
    {
        $marketing = User::factory()->create();
        $marketing->assignRole('marketing');
        $member = $this->makeMember(100);

        $this->actingAs($marketing)
            ->withHeaders(['Idempotency-Key' => $this->idempotencyKey()])
            ->postJson("/api/v1/members/{$member->uuid}/points/adjust", [
                'amount' => 200,
                'reason' => '行銷活動加點',
                'type'   => 'earn',
            ])
            ->assertOk()
            ->assertJsonPath('data.balance_after', 300);
    }

    public function test_adjust_returns_404_for_nonexistent_member(): void
    {
        $this->actingAs($this->adminUser())
            ->withHeaders(['Idempotency-Key' => $this->idempotencyKey()])
            ->postJson('/api/v1/members/00000000-0000-0000-0000-000000000000/points/adjust', [
                'amount' => 10,
                'reason' => '不存在的會員',
                'type'   => 'earn',
            ])
            ->assertNotFound();
    }

}
