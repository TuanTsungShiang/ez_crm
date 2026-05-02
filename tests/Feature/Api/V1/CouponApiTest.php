<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiCode;
use App\Models\Coupon;
use App\Models\CouponBatch;
use App\Models\Member;
use App\Models\User;
use App\Services\Coupon\CouponService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CouponApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // ── helpers ──────────────────────────────────────────────────────────

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

    private function makeMember(): Member
    {
        return Member::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Member',
            'email' => 'member'.uniqid().'@example.com',
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function makeBatchAndCode(array $batchOverrides = []): array
    {
        $batch = app(CouponService::class)->createBatch(array_merge([
            'name' => 'Test Batch',
            'type' => CouponBatch::TYPE_DISCOUNT_AMOUNT,
            'value' => 100,
            'quantity' => 3,
        ], $batchOverrides));

        return [$batch, $batch->coupons()->first()->code];
    }

    // ── POST /coupons (create batch) ───────────────────────────────────────

    public function test_admin_can_create_coupon_batch(): void
    {
        $response = $this->actingAs($this->adminUser())
            ->postJson('/api/v1/coupons', [
                'name' => '五一勞動節感謝券',
                'type' => 'discount_amount',
                'value' => 100,
                'quantity' => 10,
            ]);

        $response->assertCreated()
            ->assertJsonPath('code', ApiCode::CREATED)
            ->assertJsonPath('data.name', '五一勞動節感謝券')
            ->assertJsonPath('data.quantity', 10);

        $this->assertSame(10, Coupon::count());
    }

    public function test_create_batch_requires_coupon_manage_permission(): void
    {
        $this->actingAs($this->viewerUser())
            ->postJson('/api/v1/coupons', [
                'name' => 'test', 'type' => 'points', 'value' => 100, 'quantity' => 1,
            ])
            ->assertForbidden();
    }

    public function test_create_batch_validates_required_fields(): void
    {
        $this->actingAs($this->adminUser())
            ->postJson('/api/v1/coupons', [])
            ->assertStatus(422);
    }

    public function test_create_batch_validates_type_enum(): void
    {
        $this->actingAs($this->adminUser())
            ->postJson('/api/v1/coupons', [
                'name' => 'test', 'type' => 'invalid', 'value' => 100, 'quantity' => 1,
            ])
            ->assertStatus(422);
    }

    // ── POST /coupons/{code}/verify ────────────────────────────────────────

    public function test_verify_returns_coupon_info_for_valid_code(): void
    {
        [, $code] = $this->makeBatchAndCode();

        $response = $this->actingAs($this->adminUser())
            ->postJson("/api/v1/coupons/{$code}/verify");

        $response->assertOk()
            ->assertJsonPath('data.code', $code)
            ->assertJsonPath('data.status', 'created');
    }

    public function test_verify_returns_c002_for_expired_coupon(): void
    {
        [, $code] = $this->makeBatchAndCode(['expires_at' => now()->subDay()]);

        $this->actingAs($this->adminUser())
            ->postJson("/api/v1/coupons/{$code}/verify")
            ->assertStatus(422)
            ->assertJsonPath('code', ApiCode::COUPON_EXPIRED);
    }

    public function test_verify_returns_c001_for_already_redeemed_coupon(): void
    {
        [$batch, $code] = $this->makeBatchAndCode();
        $member = $this->makeMember();
        app(CouponService::class)->redeem($code, $member);

        $this->actingAs($this->adminUser())
            ->postJson("/api/v1/coupons/{$code}/verify")
            ->assertStatus(422)
            ->assertJsonPath('code', ApiCode::INVALID_COUPON_STATE)
            ->assertJsonPath('errors.current_status', 'redeemed');
    }

    public function test_verify_returns_404_for_unknown_code(): void
    {
        $this->actingAs($this->adminUser())
            ->postJson('/api/v1/coupons/INVALID-CODE/verify')
            ->assertNotFound();
    }

    public function test_verify_requires_coupon_view_permission(): void
    {
        $user = User::factory()->create(); // no role
        [, $code] = $this->makeBatchAndCode();

        $this->actingAs($user)
            ->postJson("/api/v1/coupons/{$code}/verify")
            ->assertForbidden();
    }

    // ── POST /coupons/{code}/redeem ────────────────────────────────────────

    public function test_redeem_transitions_coupon_to_redeemed(): void
    {
        [$batch, $code] = $this->makeBatchAndCode();
        $member = $this->makeMember();

        $response = $this->actingAs($this->adminUser())
            ->postJson("/api/v1/coupons/{$code}/redeem", [
                'member_uuid' => $member->uuid,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'redeemed')
            ->assertJsonPath('data.code', $code);

        $this->assertSame('redeemed', Coupon::where('code', $code)->value('status'));
    }

    public function test_redeem_includes_points_awarded_for_points_type(): void
    {
        [$batch, $code] = $this->makeBatchAndCode([
            'type' => 'points', 'value' => 300,
        ]);
        $member = $this->makeMember();

        $response = $this->actingAs($this->adminUser())
            ->postJson("/api/v1/coupons/{$code}/redeem", [
                'member_uuid' => $member->uuid,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.points_awarded', 300);

        $member->refresh();
        $this->assertSame(300, $member->points);
    }

    public function test_redeem_returns_c001_for_already_redeemed_coupon(): void
    {
        [$batch, $code] = $this->makeBatchAndCode();
        $member = $this->makeMember();
        app(CouponService::class)->redeem($code, $member);

        $this->actingAs($this->adminUser())
            ->postJson("/api/v1/coupons/{$code}/redeem", [
                'member_uuid' => $member->uuid,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', ApiCode::INVALID_COUPON_STATE);
    }

    public function test_redeem_returns_c002_for_expired_batch(): void
    {
        [$batch, $code] = $this->makeBatchAndCode(['expires_at' => now()->subDay()]);
        $member = $this->makeMember();

        $this->actingAs($this->adminUser())
            ->postJson("/api/v1/coupons/{$code}/redeem", [
                'member_uuid' => $member->uuid,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', ApiCode::COUPON_EXPIRED);
    }

    public function test_redeem_requires_coupon_manage_permission(): void
    {
        [$batch, $code] = $this->makeBatchAndCode();
        $member = $this->makeMember();

        $this->actingAs($this->viewerUser())
            ->postJson("/api/v1/coupons/{$code}/redeem", [
                'member_uuid' => $member->uuid,
            ])
            ->assertForbidden();
    }

    // ── POST /coupons/{code}/cancel ────────────────────────────────────────

    public function test_cancel_transitions_redeemed_to_cancelled(): void
    {
        [$batch, $code] = $this->makeBatchAndCode();
        $member = $this->makeMember();
        app(CouponService::class)->redeem($code, $member);

        $response = $this->actingAs($this->adminUser())
            ->postJson("/api/v1/coupons/{$code}/cancel");

        $response->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', Coupon::where('code', $code)->value('status'));
    }

    public function test_cancel_points_type_includes_points_refunded(): void
    {
        [$batch, $code] = $this->makeBatchAndCode([
            'type' => 'points', 'value' => 200,
        ]);
        $member = $this->makeMember();
        app(CouponService::class)->redeem($code, $member);

        $response = $this->actingAs($this->adminUser())
            ->postJson("/api/v1/coupons/{$code}/cancel");

        $response->assertOk()
            ->assertJsonPath('data.points_refunded', 200);

        $member->refresh();
        $this->assertSame(0, $member->points);
    }

    public function test_cancel_returns_c001_for_created_coupon(): void
    {
        [$batch, $code] = $this->makeBatchAndCode();

        $this->actingAs($this->adminUser())
            ->postJson("/api/v1/coupons/{$code}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('code', ApiCode::INVALID_COUPON_STATE)
            ->assertJsonPath('errors.current_status', 'created');
    }

    public function test_cancel_requires_coupon_manage_permission(): void
    {
        [$batch, $code] = $this->makeBatchAndCode();
        $member = $this->makeMember();
        app(CouponService::class)->redeem($code, $member);

        $this->actingAs($this->viewerUser())
            ->postJson("/api/v1/coupons/{$code}/cancel")
            ->assertForbidden();
    }

    // ── concurrent redeem ──────────────────────────────────────────────────

    public function test_concurrent_redeem_only_one_succeeds(): void
    {
        [$batch, $code] = $this->makeBatchAndCode(['quantity' => 1]);
        $m1 = $this->makeMember();
        $m2 = $this->makeMember();

        $results = [];
        foreach ([$m1, $m2] as $member) {
            $results[] = $this->actingAs($this->adminUser())
                ->postJson("/api/v1/coupons/{$code}/redeem", [
                    'member_uuid' => $member->uuid,
                ]);
        }

        $statuses = collect($results)->map(fn ($r) => $r->status());
        $this->assertSame(1, $statuses->filter(fn ($s) => $s === 200)->count());
        $this->assertSame(1, $statuses->filter(fn ($s) => $s === 422)->count());

        $this->assertSame('redeemed', Coupon::where('code', $code)->value('status'));
    }
}
