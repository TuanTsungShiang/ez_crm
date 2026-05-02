<?php

namespace Tests\Unit\Services;

use App\Events\Webhooks\CouponRedeemed as CouponRedeemedEvent;
use App\Exceptions\Coupon\CouponExpiredException;
use App\Exceptions\Coupon\InvalidCouponStateException;
use App\Models\Coupon;
use App\Models\CouponBatch;
use App\Models\Member;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\Coupon\CouponService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class CouponServiceTest extends TestCase
{
    use RefreshDatabase;

    private CouponService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->service = app(CouponService::class);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeMember(int $points = 0): Member
    {
        return Member::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Tester',
            'email' => 'tester'.uniqid().'@example.com',
            'status' => Member::STATUS_ACTIVE,
            'points' => $points,
        ]);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function makeBatch(array $overrides = []): CouponBatch
    {
        return $this->service->createBatch(array_merge([
            'name' => 'Test Batch',
            'type' => CouponBatch::TYPE_DISCOUNT_AMOUNT,
            'value' => 100,
            'quantity' => 3,
        ], $overrides));
    }

    private function firstCode(CouponBatch $batch): string
    {
        return $batch->coupons()->first()->code;
    }

    // ── createBatch ────────────────────────────────────────────────────────

    public function test_create_batch_generates_correct_quantity_of_unique_codes(): void
    {
        $batch = $this->makeBatch(['quantity' => 5]);

        $this->assertSame(5, Coupon::where('batch_id', $batch->id)->count());

        $codes = Coupon::where('batch_id', $batch->id)->pluck('code');
        $this->assertSame($codes->unique()->count(), $codes->count(), 'all codes must be unique');

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^EZCRM-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code);
        }
    }

    public function test_create_batch_all_coupons_start_as_created(): void
    {
        $batch = $this->makeBatch(['quantity' => 3]);

        Coupon::where('batch_id', $batch->id)->each(
            fn (Coupon $c) => $this->assertSame(Coupon::STATUS_CREATED, $c->status)
        );
    }

    // ── verify ─────────────────────────────────────────────────────────────

    public function test_verify_returns_coupon_for_valid_code(): void
    {
        $batch = $this->makeBatch();
        $member = $this->makeMember();
        $code = $this->firstCode($batch);

        $coupon = $this->service->verify($code, $member);

        $this->assertSame($code, $coupon->code);
        $this->assertSame(Coupon::STATUS_CREATED, $coupon->status, 'verify must not change status');
    }

    public function test_verify_throws_on_expired_batch(): void
    {
        $batch = $this->makeBatch(['expires_at' => now()->subDay()]);
        $code = $this->firstCode($batch);

        $this->expectException(CouponExpiredException::class);
        $this->service->verify($code);
    }

    public function test_verify_throws_for_already_redeemed_coupon(): void
    {
        $batch = $this->makeBatch();
        $member = $this->makeMember();
        $code = $this->firstCode($batch);

        $this->service->redeem($code, $member);

        $this->expectException(InvalidCouponStateException::class);
        $this->service->verify($code);
    }

    // ── redeem ─────────────────────────────────────────────────────────────

    public function test_redeem_transitions_to_redeemed_and_records_member(): void
    {
        $batch = $this->makeBatch();
        $member = $this->makeMember();
        $code = $this->firstCode($batch);

        $coupon = $this->service->redeem($code, $member);

        $this->assertSame(Coupon::STATUS_REDEEMED, $coupon->status);
        $this->assertSame($member->id, $coupon->redeemed_by);
        $this->assertNotNull($coupon->redeemed_at);
    }

    public function test_redeem_throws_invalid_state_when_already_redeemed(): void
    {
        $batch = $this->makeBatch();
        $member = $this->makeMember();
        $code = $this->firstCode($batch);

        $this->service->redeem($code, $member);

        try {
            $this->service->redeem($code, $member);
            $this->fail('Expected InvalidCouponStateException');
        } catch (InvalidCouponStateException $e) {
            $this->assertSame(Coupon::STATUS_REDEEMED, $e->currentStatus);
            $this->assertSame('redeem', $e->attemptedAction);
        }
    }

    public function test_redeem_throws_expired_when_batch_past_expiry(): void
    {
        $batch = $this->makeBatch(['expires_at' => now()->subDay()]);
        $member = $this->makeMember();
        $code = $this->firstCode($batch);

        $this->expectException(CouponExpiredException::class);
        $this->service->redeem($code, $member);
    }

    public function test_redeem_marks_coupon_expired_in_db_when_batch_expired(): void
    {
        $batch = $this->makeBatch(['expires_at' => now()->subDay()]);
        $member = $this->makeMember();
        $code = $this->firstCode($batch);

        try {
            $this->service->redeem($code, $member);
        } catch (CouponExpiredException) {
        }

        $this->assertSame(Coupon::STATUS_EXPIRED, Coupon::where('code', $code)->value('status'));
    }

    public function test_redeem_throws_invalid_state_when_cancelled(): void
    {
        $batch = $this->makeBatch();
        $member = $this->makeMember();
        $admin = $this->makeAdmin();
        $code = $this->firstCode($batch);

        $this->service->redeem($code, $member);
        $this->service->cancel($code, $admin);

        $this->expectException(InvalidCouponStateException::class);
        $this->service->redeem($code, $member);
    }

    public function test_redeem_dispatches_coupon_redeemed_event(): void
    {
        Event::fake([CouponRedeemedEvent::class]);

        $batch = $this->makeBatch();
        $member = $this->makeMember();
        $code = $this->firstCode($batch);

        $coupon = $this->service->redeem($code, $member);

        Event::assertDispatched(
            CouponRedeemedEvent::class,
            fn (CouponRedeemedEvent $e) => $e->coupon->id === $coupon->id
                && $e->member->id === $member->id,
        );
    }

    // ── redeem type=points integration ────────────────────────────────────

    public function test_redeem_points_coupon_calls_point_service(): void
    {
        $batch = $this->makeBatch(['type' => CouponBatch::TYPE_POINTS, 'value' => 200]);
        $member = $this->makeMember(0);
        $code = $this->firstCode($batch);

        $this->service->redeem($code, $member);

        $member->refresh();
        $this->assertSame(200, $member->points);
        $this->assertSame(1, PointTransaction::where('member_id', $member->id)->count());
    }

    // ── cancel ─────────────────────────────────────────────────────────────

    public function test_cancel_transitions_redeemed_to_cancelled(): void
    {
        $batch = $this->makeBatch();
        $member = $this->makeMember();
        $admin = $this->makeAdmin();
        $code = $this->firstCode($batch);

        $this->service->redeem($code, $member);
        $coupon = $this->service->cancel($code, $admin);

        $this->assertSame(Coupon::STATUS_CANCELLED, $coupon->status);
        $this->assertSame($admin->id, $coupon->cancelled_by);
        $this->assertNotNull($coupon->cancelled_at);
    }

    public function test_cancel_throws_for_created_coupon(): void
    {
        $batch = $this->makeBatch();
        $admin = $this->makeAdmin();
        $code = $this->firstCode($batch);

        try {
            $this->service->cancel($code, $admin);
            $this->fail('Expected InvalidCouponStateException');
        } catch (InvalidCouponStateException $e) {
            $this->assertSame(Coupon::STATUS_CREATED, $e->currentStatus);
            $this->assertSame('cancel', $e->attemptedAction);
        }
    }

    public function test_cancel_points_coupon_reverses_points(): void
    {
        $batch = $this->makeBatch(['type' => CouponBatch::TYPE_POINTS, 'value' => 200]);
        $member = $this->makeMember(0);
        $admin = $this->makeAdmin();
        $code = $this->firstCode($batch);

        $this->service->redeem($code, $member);
        $member->refresh();
        $this->assertSame(200, $member->points);

        $this->service->cancel($code, $admin);
        $member->refresh();
        $this->assertSame(0, $member->points, 'points must be reversed on cancel');

        $this->assertSame(2, PointTransaction::where('member_id', $member->id)->count(),
            'redeem + refund = 2 transactions');
    }

    // ── concurrency ────────────────────────────────────────────────────────

    public function test_concurrent_redeem_only_one_succeeds(): void
    {
        $batch = $this->makeBatch(['quantity' => 1]);
        $member1 = $this->makeMember();
        $member2 = $this->makeMember();
        $code = $this->firstCode($batch);

        $succeeded = 0;
        $rejected = 0;

        foreach ([$member1, $member2] as $member) {
            try {
                $this->service->redeem($code, $member);
                $succeeded++;
            } catch (InvalidCouponStateException) {
                $rejected++;
            }
        }

        $this->assertSame(1, $succeeded, 'exactly one redeem must succeed');
        $this->assertSame(1, $rejected, 'exactly one must be rejected');

        $coupon = Coupon::where('code', $code)->first();
        $this->assertSame(Coupon::STATUS_REDEEMED, $coupon->status);
    }
}
