<?php

namespace Tests\Feature\Api\V1\Me;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class MeSessionTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveMember(): Member
    {
        return Member::create([
            'uuid'              => (string) Str::uuid(),
            'name'              => 'SessionTest',
            'email'             => 'session@example.com',
            'password'          => 'Test1234',
            'status'            => Member::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function issueToken(Member $member, string $name = 'device'): string
    {
        return $member->createToken($name)->plainTextToken;
    }

    // ---- POST /me/logout ----

    public function test_logout_requires_auth(): void
    {
        $this->postJson('/api/v1/me/logout')
             ->assertStatus(401)
             ->assertJson(['code' => 'A001']);
    }

    public function test_logout_revokes_only_current_token(): void
    {
        $member = $this->makeActiveMember();
        $currentToken = $this->issueToken($member, 'current');
        $this->issueToken($member, 'other-device');
        $this->assertCount(2, $member->fresh()->tokens);

        $response = $this->withHeader('Authorization', "Bearer {$currentToken}")
                         ->postJson('/api/v1/me/logout');

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'code' => 'S200']);

        // current token gone, other-device survives
        $remaining = $member->fresh()->tokens;
        $this->assertCount(1, $remaining);
        $this->assertSame('other-device', $remaining->first()->name);
    }

    // ---- POST /me/logout-all ----

    public function test_logout_all_revokes_every_token(): void
    {
        $member = $this->makeActiveMember();
        $t1 = $this->issueToken($member, 'phone');
        $this->issueToken($member, 'ipad');
        $this->issueToken($member, 'desktop');
        $this->assertCount(3, $member->fresh()->tokens);

        $response = $this->withHeader('Authorization', "Bearer {$t1}")
                         ->postJson('/api/v1/me/logout-all');

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);

        $this->assertCount(0, $member->fresh()->tokens);
    }

    // ---- DELETE /me ----

    public function test_destroy_soft_deletes_and_revokes_all_tokens(): void
    {
        $member = $this->makeActiveMember();
        $token = $this->issueToken($member, 'current');
        $this->issueToken($member, 'other');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
                         ->deleteJson('/api/v1/me');

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);

        // soft delete: deleted_at set
        $this->assertSoftDeleted('members', ['email' => 'session@example.com']);

        // all tokens cleared
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $member->id)->count());
    }

    public function test_destroy_requires_auth(): void
    {
        $this->deleteJson('/api/v1/me')
             ->assertStatus(401)
             ->assertJson(['code' => 'A001']);
    }
}
