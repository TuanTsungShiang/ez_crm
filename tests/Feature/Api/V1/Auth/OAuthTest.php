<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Member;
use App\Models\MemberSns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    private function mockSocialiteUser(string $provider, SocialiteUserContract $user): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($user);

        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    private function mockSocialiteFailure(string $provider): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')
               ->andThrow(new \RuntimeException('Simulated Socialite failure'));

        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    /** 將有效 state 寫入 cache，回傳 state 字串供 callback URL 使用 */
    private function seedOAuthState(): string
    {
        $state = Str::random(40);
        Cache::put("oauth_state_{$state}", true, now()->addMinutes(10));
        return $state;
    }

    // ---- redirect ----

    public function test_redirect_returns_authorization_url(): void
    {
        $redirect = Mockery::mock();
        $redirect->shouldReceive('getTargetUrl')
                 ->andReturn('https://accounts.google.com/o/oauth2/auth?client_id=xxx');

        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('with')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn($redirect);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->getJson('/api/v1/auth/oauth/google/redirect');

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('code', 'S200')
                 ->assertJsonStructure(['data' => ['url']]);
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
        $this->getJson('/api/v1/auth/oauth/bogus/callback?format=json&code=xxx')
             ->assertStatus(422)
             ->assertJson(['code' => 'A013']);
    }

    // ---- state 驗證 ----

    public function test_callback_rejects_missing_state(): void
    {
        $this->getJson('/api/v1/auth/oauth/google/callback?format=json&code=fake')
             ->assertStatus(400)
             ->assertJson(['success' => false, 'code' => 'A010']);
    }

    public function test_callback_rejects_invalid_state(): void
    {
        $this->getJson('/api/v1/auth/oauth/google/callback?format=json&code=fake&state=not-a-real-state')
             ->assertStatus(400)
             ->assertJson(['success' => false, 'code' => 'A010']);
    }

    public function test_callback_rejects_replayed_state(): void
    {
        $state = $this->seedOAuthState();

        $this->mockSocialiteUser('google', $this->fakeSocialiteUser());
        $this->getJson("/api/v1/auth/oauth/google/callback?format=json&code=fake&state={$state}")
             ->assertStatus(200);

        // 第二次用同一個 state 應該被拒絕（已被 Cache::pull 消耗）
        $this->mockSocialiteUser('google', $this->fakeSocialiteUser());
        $this->getJson("/api/v1/auth/oauth/google/callback?format=json&code=fake&state={$state}")
             ->assertStatus(400)
             ->assertJson(['code' => 'A010']);
    }

    // ---- callback scenario 3: 全新使用者 ----

    public function test_callback_creates_new_member_when_fully_new(): void
    {
        $state = $this->seedOAuthState();

        $this->mockSocialiteUser('google', $this->fakeSocialiteUser([
            'id'    => 'google-new-user',
            'email' => 'brand-new@example.com',
            'name'  => '新人',
        ]));

        $response = $this->getJson("/api/v1/auth/oauth/google/callback?format=json&code=fake&state={$state}");

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'code'    => 'S200',
                     'data'    => ['is_new_account' => true, 'newly_bound' => true],
                 ]);

        $member = Member::where('email', 'brand-new@example.com')->first();
        $this->assertNotNull($member);
        $this->assertNotNull($member->email_verified_at, 'OAuth 提供 email 視同驗證');
        $this->assertTrue($member->profile()->exists());
        $this->assertDatabaseHas('member_sns', [
            'member_id'        => $member->id,
            'provider'         => 'google',
            'provider_user_id' => 'google-new-user',
        ]);
    }

    // ---- callback scenario 2: email 已存在,自動綁定 ----

    public function test_callback_auto_binds_when_email_already_exists(): void
    {
        $state = $this->seedOAuthState();

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
        ]));

        $response = $this->getJson("/api/v1/auth/oauth/google/callback?format=json&code=fake&state={$state}");

        $response->assertStatus(200)
                 ->assertJson([
                     'data' => ['is_new_account' => false, 'newly_bound' => true],
                 ]);

        $this->assertSame(1, Member::where('email', 'existing@example.com')->count());
        $this->assertDatabaseHas('member_sns', [
            'member_id'        => $existing->id,
            'provider'         => 'google',
            'provider_user_id' => 'google-auto-bind-uid',
        ]);
    }

    // ---- callback scenario 1: SNS 已綁,直接登入 ----

    public function test_callback_logs_in_when_sns_already_bound(): void
    {
        $state = $this->seedOAuthState();

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

        $response = $this->getJson("/api/v1/auth/oauth/google/callback?format=json&code=fake&state={$state}");

        $response->assertStatus(200)
                 ->assertJson([
                     'data' => ['is_new_account' => false, 'newly_bound' => false],
                 ]);

        $this->assertDatabaseHas('member_sns', [
            'provider_user_id' => 'google-repeat-uid',
            'access_token'     => 'fake-access-token',
        ]);
        $this->assertSame(1, Member::count());
        $this->assertSame(1, MemberSns::count());
    }

    // ---- callback failure ----

    public function test_callback_returns_oauth_failed_on_socialite_exception(): void
    {
        $state = $this->seedOAuthState();
        $this->mockSocialiteFailure('google');

        $this->getJson("/api/v1/auth/oauth/google/callback?format=json&code=invalid&state={$state}")
             ->assertStatus(400)
             ->assertJson(['success' => false, 'code' => 'A010']);
    }

    // ---- github provider ----

    public function test_github_callback_creates_member_and_binds_sns(): void
    {
        $state = $this->seedOAuthState();

        $this->mockSocialiteUser('github', $this->fakeSocialiteUser([
            'id'       => 'github-uid-999',
            'email'    => 'gh-user@example.com',
            'name'     => 'GH User',
            'nickname' => 'gh-handle',
        ]));

        $this->getJson("/api/v1/auth/oauth/github/callback?format=json&code=fake&state={$state}")
             ->assertStatus(200)
             ->assertJson(['data' => ['is_new_account' => true, 'newly_bound' => true]]);

        $member = Member::where('email', 'gh-user@example.com')->first();
        $this->assertNotNull($member);
        $this->assertDatabaseHas('member_sns', [
            'member_id' => $member->id,
            'provider'  => 'github',
        ]);
        $this->assertDatabaseHas('member_login_histories', [
            'member_id'    => $member->id,
            'login_method' => 'github',
            'status'       => true,
        ]);
    }

    // ---- line provider ----

    public function test_line_callback_creates_member_with_placeholder_email(): void
    {
        $state = $this->seedOAuthState();

        // LINE 不一定提供 email，用 null 模擬
        $this->mockSocialiteUser('line', $this->fakeSocialiteUser([
            'id'    => 'line-uid-123',
            'email' => null,
            'name'  => 'LINE 使用者',
        ]));

        $this->getJson("/api/v1/auth/oauth/line/callback?format=json&code=fake&state={$state}")
             ->assertStatus(200)
             ->assertJson(['data' => ['is_new_account' => true]]);

        // placeholder email 格式 line_{id}@oauth.local
        $this->assertDatabaseHas('members', [
            'email'             => 'line_line-uid-123@oauth.local',
            'email_verified_at' => null,
        ]);
    }

    public function test_line_callback_with_email_marks_verified(): void
    {
        $state = $this->seedOAuthState();

        $this->mockSocialiteUser('line', $this->fakeSocialiteUser([
            'id'    => 'line-uid-456',
            'email' => 'line-user@example.com',
            'name'  => 'LINE 使用者',
        ]));

        $this->getJson("/api/v1/auth/oauth/line/callback?format=json&code=fake&state={$state}")
             ->assertStatus(200);

        $member = Member::where('email', 'line-user@example.com')->first();
        $this->assertNotNull($member->email_verified_at);
    }

    // ---- discord provider ----

    public function test_discord_callback_creates_member_with_email(): void
    {
        $state = $this->seedOAuthState();

        $this->mockSocialiteUser('discord', $this->fakeSocialiteUser([
            'id'       => 'discord-uid-789',
            'email'    => 'discord-user@example.com',
            'name'     => 'Discord 使用者',
            'nickname' => 'discord_handle',
        ]));

        $this->getJson("/api/v1/auth/oauth/discord/callback?format=json&code=fake&state={$state}")
             ->assertStatus(200)
             ->assertJson(['data' => ['is_new_account' => true, 'newly_bound' => true]]);

        $member = Member::where('email', 'discord-user@example.com')->first();
        $this->assertNotNull($member);
        $this->assertNotNull($member->email_verified_at);
        $this->assertDatabaseHas('member_sns', [
            'member_id' => $member->id,
            'provider'  => 'discord',
        ]);
    }

    // ---- side effects ----

    public function test_callback_records_login_history_and_issues_token(): void
    {
        $state = $this->seedOAuthState();

        $this->mockSocialiteUser('google', $this->fakeSocialiteUser([
            'id'    => 'history-check-uid',
            'email' => 'history@example.com',
        ]));

        $this->getJson("/api/v1/auth/oauth/google/callback?format=json&code=fake&state={$state}")
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

    // ---- HTML + postMessage (default for SPA popup flow) ----

    public function test_callback_returns_html_with_postmessage_by_default(): void
    {
        $state = Str::random(40);
        \Illuminate\Support\Facades\Cache::put("oauth_state_{$state}", true, now()->addMinutes(10));

        $this->mockSocialiteUser('google', $this->fakeSocialiteUser([
            'id'    => 'html-test-uid',
            'email' => 'html-test@example.com',
        ]));

        // 注意：不帶 ?format=json,預期回 HTML
        $response = $this->get("/api/v1/auth/oauth/google/callback?code=fake&state={$state}");

        $response->assertStatus(200);
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));

        $body = $response->getContent();
        // postMessage 呼叫要存在 + 帶正確 type
        $this->assertStringContainsString('window.opener.postMessage', $body);
        $this->assertStringContainsString('ez_crm_oauth_result', $body);
        // targetOrigin 不是 '*'（避免 XSS 風險）
        $this->assertStringNotContainsString("postMessage(payload, '*')", $body);
        // 成功 flag
        $this->assertStringContainsString('"success":true', $body);
    }

    public function test_callback_html_on_invalid_state_shows_error(): void
    {
        $response = $this->get('/api/v1/auth/oauth/google/callback?code=fake&state=bogus-state');

        $response->assertStatus(400);
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $body = $response->getContent();
        $this->assertStringContainsString('"success":false', $body);
        $this->assertStringContainsString('A010', $body);
    }
}
