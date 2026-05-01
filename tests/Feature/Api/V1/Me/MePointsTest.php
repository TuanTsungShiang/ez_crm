<?php

namespace Tests\Feature\Api\V1\Me;

use App\Models\Member;
use App\Services\Points\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MePointsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_me_points_returns_own_balance_and_transactions(): void
    {
        $member = $this->makeMember(300);

        app(PointService::class)->adjust($member, 100, '加點', 'earn', (string) Str::uuid());

        $response = $this->actingAs($member, 'member')
            ->getJson('/api/v1/me/points');

        $response->assertOk()
            ->assertJsonPath('data.balance', 400)
            ->assertJsonPath('data.transactions.total', 1);
    }

    public function test_me_points_returns_only_own_transactions(): void
    {
        $member  = $this->makeMember(100);
        $other   = $this->makeMember(100);

        app(PointService::class)->adjust($member, 50, '我的加點', 'earn', (string) Str::uuid());
        app(PointService::class)->adjust($other,  50, '別人加點', 'earn', (string) Str::uuid());

        $response = $this->actingAs($member, 'member')
            ->getJson('/api/v1/me/points');

        $response->assertOk()
            ->assertJsonPath('data.transactions.total', 1);
    }

    public function test_me_points_requires_member_authentication(): void
    {
        $this->getJson('/api/v1/me/points')
            ->assertUnauthorized();
    }
}
