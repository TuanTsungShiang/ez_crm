<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    private string $endpoint = '/api/v1/auth/login';

    private function makeActiveMember(array $overrides = []): Member
    {
        return Member::create(array_merge([
            'uuid'              => (string) Str::uuid(),
            'name'              => 'Login測試',
            'email'             => 'login@example.com',
            'password'          => 'Test1234',
            'status'            => Member::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_successful_login_returns_token_and_records_history(): void
    {
        $member = $this->makeActiveMember();

        $response = $this->postJson($this->endpoint, [
            'email'    => 'login@example.com',
            'password' => 'Test1234',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'code' => 'S200'])
                 ->assertJsonStructure(['data' => ['token', 'member' => ['uuid', 'name', 'email']]]);

        $this->assertCount(1, $member->fresh()->tokens);
        $this->assertDatabaseHas('member_login_histories', [
            'member_id'    => $member->id,
            'login_method' => 'email',
            'status'       => true,
        ]);
        $this->assertNotNull($member->fresh()->last_login_at);
    }

    public function test_wrong_password_rejected_and_records_failure(): void
    {
        $member = $this->makeActiveMember();

        $response = $this->postJson($this->endpoint, [
            'email'    => 'login@example.com',
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'code' => 'A009']);

        $this->assertDatabaseHas('member_login_histories', [
            'member_id' => $member->id,
            'status'    => false,
        ]);
    }

    public function test_unknown_email_rejected(): void
    {
        $response = $this->postJson($this->endpoint, [
            'email'    => 'nobody@example.com',
            'password' => 'AnyPass',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'A009']);

        $this->assertDatabaseCount('member_login_histories', 0);
    }

    public function test_inactive_member_rejected(): void
    {
        $this->makeActiveMember(['status' => Member::STATUS_INACTIVE]);

        $response = $this->postJson($this->endpoint, [
            'email'    => 'login@example.com',
            'password' => 'Test1234',
        ]);

        $response->assertStatus(403)
                 ->assertJson(['code' => 'A004']);
    }

    public function test_unverified_email_rejected(): void
    {
        $this->makeActiveMember(['email_verified_at' => null]);

        $response = $this->postJson($this->endpoint, [
            'email'    => 'login@example.com',
            'password' => 'Test1234',
        ]);

        $response->assertStatus(403)
                 ->assertJson(['code' => 'A005']);
    }
}
