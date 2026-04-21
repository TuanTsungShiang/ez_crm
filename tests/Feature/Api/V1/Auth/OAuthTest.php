<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Member;
use App\Models\MemberSns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class OAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 產生一個假的 Socialite user（實作 SocialiteUserContract）。
     */
    private function fakeSocialiteUser(array $overrides = []): SocialiteUserContract
    {
        $attrs = array_merge([
            'id'       => 'google-uid-100',
            'email'    => 'google-user@example.com',
            'name'     => 'Google 使用者',
            'nickname' => null,
            'avatar'   => 'https://lh3.googleusercontent.com/avatar.png',
        ], $overrides);

        $user = Mockery::mock(SocialiteUserContract::class);
        $user->shouldReceive('getId')->andReturn($attrs['id']);
        $user->shouldReceive('getEmail')->andReturn($attrs['email']);
        $user->shouldReceive('getName')->andReturn($attrs['name']);
        $user->shouldReceive('getNickname')->andReturn($attrs['nickname']);
        $user->shouldReceive('getAvatar')->andReturn($attrs['avatar']);
        $user->token        = 'fake-access-token';
        $user->refreshToken = 'fake-refresh-token';
        $user->expiresIn    = 3600;

        return $user;
    }

    /**
     * 讓 Socialite::driver($provider)->stateless()->user() 回傳指定的假 user。
     */
    private function mockSocialiteUser(string $provider, SocialiteUserContract $user): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($user);

        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    /**
     * 讓 Socialite::driver(...)->stateless()->user() 丟例外（模擬 OAuth 失敗）。
     */
    private function mockSocialiteFailure(string $provider): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')
               ->andThrow(new \RuntimeException('Simulated Socialite failure'));

        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    // ---- redirect ----

    public function test_redirect_returns_authorization_url(): void
    {
        $redirect = Mockery::mock();
        $redirect->shouldReceive('getTargetUrl')
                 ->andReturn('https://accounts.google.com/o/oauth2/auth?client_id=xxx');

        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn($redirect);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->getJson('/api/v1/auth/oauth/google/redirect');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'code'    => 'S200',
                     'data'    => ['url' => 'https://accounts.google.com/o/oauth2/auth?client_id=xxx'],
                 ]);
    }

    // ---- unsupported provider ----

    public function test_redirect_rejects_unsupported_provider(): void
    {
        $this->getJson('/api/v1/auth/oauth/bogus/redirect')
             ->assertStatus(422)
             ->assertJson(['success' => false, 'code' => 'A013']);
    }

    public function test_callback_rejects_unsupported_provider(): void
    {
        $this->getJson('/api/v1/auth/oauth/bogus/callback?code=xxx')
             ->assertStatus(422)
             ->assertJson(['code' => 'A013']);
    }

    // ---- callback scenario 3: 全新使用者 ----

    public function test_callback_creates_new_member_when_fully_new(): void
    {
        $this->mockSocialiteUser('google', $this->fakeSocialiteUser([
            'id'    => 'google-new-user',
            'email' => 'brand-new@example.com',
            'name'  => '新人',
        ]));

        $response = $this->getJson('/api/v1/auth/oauth/google/callback?code=fake');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'code'    => 'S200',
                     'data'    => [
                         'is_new_account' => true,
                         'newly_bound'    => true,
                     ],
                 ]);

        $this->assertDatabaseHas('members', [
            'email'  => 'brand-new@example.com',
            'status' => Member::STATUS_ACTIVE,
        ]);

        $member = Member::where('email', 'brand-new@example.com')->first();
        $this->assertNotNull($member->email_verified_at, 'OAuth 提供 email 視同驗證');
        $this->assertTrue($member->profile()->exists(), 'Profile 應同步建立');
        $this->assertDatabaseHas('member_sns', [
            'member_id'        => $member->id,
            'provider'         => 'google',
            'provider_user_id' => 'google-new-user',
        ]);
    }

    // ---- callback scenario 2: email 已存在,自動綁定 ----

    public function test_callback_auto_binds_when_email_already_exists(): void
    {
        $existing = Member::create([
            'uuid'              => (string) Str::uuid(),
            'name'              => '既有會員',
            'email'             => 'existing@example.com',
            'password'          => 'Secret123',
            'status'            => Member::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $this->mockSocialiteUser('google', $this->fakeSocialiteUser([
            'id'    => 'google-auto-bind-uid',
            'email' => 'existing@example.com',
            'name'  => 'Google Name',
        ]));

        $response = $this->getJson('/api/v1/auth/oauth/google/callback?code=fake');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'data'    => [
                         'is_new_account' => false,
                         'newly_bound'    => true,
                         'member'         => ['email' => 'existing@example.com'],
                     ],
                 ]);

        // 沒有建新 member
        $this->assertSame(1, Member::where('email', 'existing@example.com')->count());

        // 但有綁定 SNS
        $this->assertDatabaseHas('member_sns', [
            'member_id'        => $existing->id,
            'provider'         => 'google',
            'provider_user_id' => 'google-auto-bind-uid',
        ]);
    }

    // ---- callback scenario 1: SNS 已綁,直接登入 ----

    public function test_callback_logs_in_when_sns_already_bound(): void
    {
        $member = Member::create([
            'uuid'              => (string) Str::uuid(),
            'name'              => '老手',
            'email'             => 'bound@example.com',
            'password'          => 'Secret123',
            'status'            => Member::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        MemberSns::create([
            'member_id'        => $member->id,
            'provider'         => 'google',
            'provider_user_id' => 'google-repeat-uid',
            'access_token'     => 'old-token',
        ]);

        $this->mockSocialiteUser('google', $this->fakeSocialiteUser([
            'id'    => 'google-repeat-uid',
            'email' => 'bound@example.com',
        ]));

        $response = $this->getJson('/api/v1/auth/oauth/google/callback?code=fake');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'data'    => [
                         'is_new_account' => false,
                         'newly_bound'    => false,
                     ],
                 ]);

        // SNS token 應該被更新
        $this->assertDatabaseHas('member_sns', [
            'member_id'        => $member->id,
            'provider_user_id' => 'google-repeat-uid',
            'access_token'     => 'fake-access-token',
        ]);

        // 不應建新 member、新 SNS
        $this->assertSame(1, Member::count());
        $this->assertSame(1, MemberSns::count());
    }

    // ---- callback failure ----

    public function test_callback_returns_oauth_failed_on_socialite_exception(): void
    {
        $this->mockSocialiteFailure('google');

        $response = $this->getJson('/api/v1/auth/oauth/google/callback?code=invalid');

        $response->assertStatus(400)
                 ->assertJson(['success' => false, 'code' => 'A010']);
    }

    // ---- github provider (proves service is provider-agnostic) ----

    public function test_github_callback_creates_member_and_binds_sns(): void
    {
        $this->mockSocialiteUser('github', $this->fakeSocialiteUser([
            'id'       => 'github-uid-999',
            'email'    => 'gh-user@example.com',
            'name'     => 'GH User',
            'nickname' => 'gh-handle',
        ]));

        $response = $this->getJson('/api/v1/auth/oauth/github/callback?code=fake');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'code'    => 'S200',
                     'data'    => ['is_new_account' => true, 'newly_bound' => true],
                 ]);

        $member = Member::where('email', 'gh-user@example.com')->first();
        $this->assertNotNull($member);

        $this->assertDatabaseHas('member_sns', [
            'member_id'        => $member->id,
            'provider'         => 'github',
            'provider_user_id' => 'github-uid-999',
        ]);

        $this->assertDatabaseHas('member_login_histories', [
            'member_id'    => $member->id,
            'login_method' => 'github',
            'status'       => true,
        ]);
    }

    // ---- side effects ----

    public function test_callback_records_login_history_and_issues_token(): void
    {
        $this->mockSocialiteUser('google', $this->fakeSocialiteUser([
            'id'    => 'history-check-uid',
            'email' => 'history@example.com',
        ]));

        $this->getJson('/api/v1/auth/oauth/google/callback?code=fake')
             ->assertStatus(200);

        $member = Member::where('email', 'history@example.com')->first();

        $this->assertDatabaseHas('member_login_histories', [
            'member_id'    => $member->id,
            'login_method' => 'google',
            'status'       => true,
        ]);

        $this->assertCount(1, $member->tokens);
        $this->assertNotNull($member->fresh()->last_login_at);
    }
}
