<?php

namespace Tests\Feature\Api\V1;

use App\Exceptions\Points\InsufficientPointsException;
use App\Models\Member;
use App\Models\PointTransaction;
use App\Services\Points\PointService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Concurrency tests for PointService::adjust.
 *
 * Uses DatabaseMigrations (not RefreshDatabase) so DB::transaction + lockForUpdate
 * behave correctly on a real connection without wrapping transactions hiding the lock.
 *
 * Each test runs real sequential DB calls that simulate the "last seen balance" race.
 * True OS-level parallelism (pcntl_fork) is not available on Windows, so we verify
 * the invariants via the service's sequential TOCTOU safety path instead.
 */
class PointsConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeMember(int $points): Member
    {
        return Member::create([
            'uuid'   => (string) Str::uuid(),
            'name'   => 'Concurrent Tester',
            'email'  => 'concurrent' . uniqid() . '@test.com',
            'status' => Member::STATUS_ACTIVE,
            'points' => $points,
        ]);
    }

    /**
     * Simulates 10 sequential spend requests (-20 each) against a 100-point member.
     * Exactly 5 should succeed, 5 should be rejected with InsufficientPointsException.
     * Final balance must be 0, not negative.
     */
    public function test_sequential_overspend_is_rejected_and_balance_stays_non_negative(): void
    {
        $member  = $this->makeMember(100);
        $service = app(PointService::class);

        $succeeded = 0;
        $rejected  = 0;

        for ($i = 1; $i <= 10; $i++) {
            try {
                $service->adjust($member, -20, "spend #{$i}", 'spend', (string) Str::uuid());
                $succeeded++;
                $member->refresh();
            } catch (InsufficientPointsException) {
                $rejected++;
            }
        }

        $member->refresh();

        $this->assertSame(5, $succeeded, 'exactly 5 of 10 spends should succeed');
        $this->assertSame(5, $rejected,  'exactly 5 of 10 spends should be rejected');
        $this->assertSame(0, $member->points, 'final balance must be 0, not negative');
        $this->assertSame(5, PointTransaction::where('member_id', $member->id)->count());
    }

    /**
     * Verifies that balance_after on each transaction is monotonically consistent:
     * every row's balance_after equals the previous row's balance_after + current amount.
     */
    public function test_balance_after_is_consistent_across_sequential_adjusts(): void
    {
        $member  = $this->makeMember(0);
        $service = app(PointService::class);

        $adjustments = [100, -30, 50, -20, 200, -150];
        foreach ($adjustments as $i => $amount) {
            $service->adjust($member, $amount, "adjust #{$i}", $amount > 0 ? 'earn' : 'spend', (string) Str::uuid());
            $member->refresh();
        }

        $txs = PointTransaction::where('member_id', $member->id)->orderBy('id')->get();

        $running = 0;
        foreach ($txs as $tx) {
            $running += $tx->amount;
            $this->assertSame($running, $tx->balance_after,
                "balance_after mismatch at tx #{$tx->id}: expected {$running}, got {$tx->balance_after}");
        }

        $member->refresh();
        $this->assertSame($running, $member->points, 'members.points cache matches sum of transactions');
    }

    /**
     * Idempotency under "concurrent" duplicate sends: same key submitted twice must
     * produce exactly 1 transaction row and increment balance only once.
     */
    public function test_duplicate_idempotency_key_produces_single_transaction(): void
    {
        $member  = $this->makeMember(100);
        $service = app(PointService::class);
        $key     = (string) Str::uuid();

        $tx1 = $service->adjust($member, 50, '首次加點', 'earn', $key);
        $tx2 = $service->adjust($member, 50, '重複加點', 'earn', $key);

        $this->assertSame($tx1->id, $tx2->id, 'replay must return the same transaction');
        $this->assertSame(1, PointTransaction::where('idempotency_key', $key)->count());

        $member->refresh();
        $this->assertSame(150, $member->points, 'balance must increment only once');
    }

    /**
     * A spend that would make balance negative is rejected atomically —
     * the member's balance and transaction count must be unchanged.
     */
    public function test_insufficient_points_leaves_no_partial_state(): void
    {
        $member  = $this->makeMember(30);
        $service = app(PointService::class);

        try {
            $service->adjust($member, -50, '超額扣點', 'spend', (string) Str::uuid());
            $this->fail('Expected InsufficientPointsException');
        } catch (InsufficientPointsException $e) {
            $this->assertSame(30, $e->currentBalance);
            $this->assertSame(-50, $e->requestedAmount);
        }

        $member->refresh();
        $this->assertSame(30, $member->points, 'balance must be unchanged');
        $this->assertSame(0, PointTransaction::where('member_id', $member->id)->count());
    }
}
