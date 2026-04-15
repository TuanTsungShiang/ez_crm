<?php

namespace Tests\Unit\Services;

use App\Models\Member;
use App\Services\MemberSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_sort_by_falls_back_to_created_at(): void
    {
        $older = $this->createMember('Older', 'older@example.com', '2026-01-01 00:00:00', '2026-01-02 00:00:00');
        $newer = $this->createMember('Newer', 'newer@example.com', '2026-03-01 00:00:00', '2026-03-02 00:00:00');

        $result = app(MemberSearchService::class)->search([
            'sort_by'  => 'invalid_column',
            'per_page' => 15,
        ]);

        $items = $result->items();

        $this->assertSame($newer->id, $items[0]->id);
        $this->assertSame($older->id, $items[1]->id);
    }

    public function test_invalid_sort_dir_falls_back_to_desc(): void
    {
        $older = $this->createMember('Alpha', 'alpha@example.com', '2026-01-01 00:00:00', '2026-01-01 00:00:00');
        $newer = $this->createMember('Beta', 'beta@example.com', '2026-04-01 00:00:00', '2026-04-01 00:00:00');

        $result = app(MemberSearchService::class)->search([
            'sort_dir' => 'sideways',
            'per_page' => 15,
        ]);

        $items = $result->items();

        $this->assertSame($newer->id, $items[0]->id);
        $this->assertSame($older->id, $items[1]->id);
    }

    public function test_valid_sort_by_and_sort_dir_still_work(): void
    {
        $alpha = $this->createMember('Alpha', 'alpha@example.com', '2026-02-01 00:00:00', '2026-02-01 00:00:00');
        $beta = $this->createMember('Beta', 'beta@example.com', '2026-01-01 00:00:00', '2026-01-01 00:00:00');

        $result = app(MemberSearchService::class)->search([
            'sort_by'  => 'name',
            'sort_dir' => 'asc',
            'per_page' => 15,
        ]);

        $items = $result->items();

        $this->assertSame($alpha->id, $items[0]->id);
        $this->assertSame($beta->id, $items[1]->id);
    }

    private function createMember(string $name, string $email, string $createdAt, string $lastLoginAt): Member
    {
        $createdAt = Carbon::parse($createdAt);
        $lastLoginAt = Carbon::parse($lastLoginAt);

        $member = Member::create([
            'uuid'     => (string) Str::uuid(),
            'name'     => $name,
            'email'    => $email,
            'password' => bcrypt('password'),
            'status'   => 1,
        ]);

        $member->forceFill([
            'created_at'    => $createdAt,
            'updated_at'    => $createdAt,
            'last_login_at' => $lastLoginAt,
        ])->saveQuietly();

        return $member->fresh();
    }
}
