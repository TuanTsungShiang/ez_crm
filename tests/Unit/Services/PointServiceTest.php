<?php

namespace Tests\Unit\Services;

use App\Events\Webhooks\PointAdjusted;
use App\Exceptions\Points\InsufficientPointsException;
use App\Models\Member;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\Points\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class PointServiceTest extends TestCase
{
    use RefreshDatabase;

    private PointService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PointService::class);
    }

    private function makeMember(int $points = 0): Member
    {
        return Member::create([
            'uuid'   => (string) Str::uuid(),
            'name'   => 'Tester',
            'email'  => 'tester'.uniqid().'@example.com',
            'status' => Member::STATUS_ACTIVE,
            'points' => $points,
        ]);
    }

    public function test_adjust_adds_points_and_writes_transaction(): void
    {
        $member = $this->makeMember(100);

        $tx = $this->service->adjust($member, 50, '加點測試', 'earn', (string) Str::uuid());

        $this->assertSame(50, $tx->amount);
        $this->assertSame(150, $tx->balance_after);
        $this->assertSame('earn', $tx->type);
        $member->refresh();
        $this->assertSame(150, $member->points);
    }

    public function test_adjust_deducts_points(): void
    {
        $member = $this->makeMember(200);

        $tx = $this->service->adjust($member, -75, '扣點測試', 'spend', (string) Str::uuid());

        $this->assertSame(-75, $tx->amount);
        $this->assertSame(125, $tx->balance_after);
        $member->refresh();
        $this->assertSame(125, $member->points);
    }

    public function test_adjust_rejects_zero_amount(): void
    {
        $member = $this->makeMember(100);

        $this->expectException(InvalidArgumentException::class);
        $this->service->adjust($member, 0, 'no-op', 'adjust', (string) Str::uuid());
    }

    public function test_adjust_rejects_invalid_type(): void
    {
        $member = $this->makeMember(100);

        $this->expectException(InvalidArgumentException::class);
        $this->service->adjust($member, 10, 'bad-type', 'whatever', (string) Str::uuid());
    }

    public function test_adjust_throws_insufficient_points_when_balance_would_go_negative(): void
    {
        $member = $this->makeMember(50);

        try {
            $this->service->adjust($member, -100, 'over-deduct', 'spend', (string) Str::uuid());
            $this->fail('Expected InsufficientPointsException');
        } catch (InsufficientPointsException $e) {
            $this->assertSame($member->id, $e->memberId);
            $this->assertSame(50, $e->currentBalance);
            $this->assertSame(-100, $e->requestedAmount);
        }

        $member->refresh();
        $this->assertSame(50, $member->points, 'balance must remain unchanged after rollback');
        $this->assertSame(0, PointTransaction::count(), 'no transaction row should be persisted on rollback');
    }

    public function test_adjust_idempotency_key_returns_same_transaction_without_replay(): void
    {
        $member = $this->makeMember(100);
        $key    = (string) Str::uuid();

        $first  = $this->service->adjust($member, 50, '第一次', 'earn', $key);
        $second = $this->service->adjust($member, 50, '第二次(replay)', 'earn', $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PointTransaction::count());
        $member->refresh();
        $this->assertSame(150, $member->points, 'points must increment only once');
    }

    public function test_adjust_dispatches_point_adjusted_event(): void
    {
        Event::fake([PointAdjusted::class]);
        $member = $this->makeMember(100);

        $tx = $this->service->adjust($member, 30, '事件測試', 'earn', (string) Str::uuid());

        Event::assertDispatched(
            PointAdjusted::class,
            fn (PointAdjusted $e) => $e->member->id === $member->id && $e->transaction->id === $tx->id,
        );
    }

    public function test_adjust_records_actor_when_user_is_authenticated(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $member = $this->makeMember(100);
        $tx     = $this->service->adjust($member, 20, 'actor 測試', 'adjust', (string) Str::uuid());

        $this->assertSame($admin->id, $tx->actor_id);
        $this->assertSame(PointTransaction::ACTOR_USER, $tx->actor_type);
    }

    public function test_adjust_records_system_actor_when_no_authenticated_user(): void
    {
        $member = $this->makeMember(100);
        $tx     = $this->service->adjust($member, 10, 'system 測試', 'earn', (string) Str::uuid());

        $this->assertNull($tx->actor_id);
        $this->assertSame(PointTransaction::ACTOR_SYSTEM, $tx->actor_type);
    }

    public function test_adjust_records_polymorphic_source(): void
    {
        $member = $this->makeMember(100);
        $source = $this->makeMember();

        $tx = $this->service->adjust($member, 10, 'source 測試', 'earn', (string) Str::uuid(), $source);

        $this->assertSame(Member::class, $tx->source_type);
        $this->assertSame($source->id, $tx->source_id);
    }

    public function test_balance_after_is_continuously_correct_across_multiple_adjusts(): void
    {
        $member = $this->makeMember(0);

        $this->service->adjust($member, 100, '+100', 'earn',  (string) Str::uuid());
        $this->service->adjust($member, -30, '-30',  'spend', (string) Str::uuid());
        $this->service->adjust($member, 50,  '+50',  'earn',  (string) Str::uuid());

        $txs = PointTransaction::orderBy('id')->get();
        $this->assertSame(100, $txs[0]->balance_after);
        $this->assertSame(70,  $txs[1]->balance_after);
        $this->assertSame(120, $txs[2]->balance_after);

        $member->refresh();
        $this->assertSame(120, $member->points);
    }
}
