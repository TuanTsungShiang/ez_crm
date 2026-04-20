<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Member;
use App\Models\MemberVerification;
use App\Notifications\Member\SendOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function makePendingMember(string $email = 'pending@example.com'): Member
    {
        return Member::create([
            'uuid'     => (string) \Illuminate\Support\Str::uuid(),
            'name'     => 'Pending',
            'email'    => $email,
            'password' => 'secret',
            'status'   => Member::STATUS_PENDING,
        ]);
    }

    private function makeOtp(Member $member, string $code = '123456', string $type = MemberVerification::TYPE_EMAIL): MemberVerification
    {
        return MemberVerification::create([
            'member_id'  => $member->id,
            'type'       => $type,
            'token'      => $code,
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
        ]);
    }

    // ---- verify/email ----

    public function test_verify_with_valid_code_activates_and_returns_token(): void
    {
        $member = $this->makePendingMember();
        $this->makeOtp($member, '111222');

        $response = $this->postJson('/api/v1/auth/verify/email', [
            'email' => $member->email,
            'code'  => '111222',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'code' => 'S200'])
                 ->assertJsonStructure(['data' => ['token', 'member' => ['uuid', 'name', 'email']]]);

        $member->refresh();
        $this->assertNotNull($member->email_verified_at);
        $this->assertSame(Member::STATUS_ACTIVE, $member->status);
        $this->assertCount(1, $member->tokens);
    }

    public function test_wrong_code_rejected(): void
    {
        $member = $this->makePendingMember();
        $this->makeOtp($member, '111222');

        $response = $this->postJson('/api/v1/auth/verify/email', [
            'email' => $member->email,
            'code'  => '000000',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'code' => 'A007']);
    }

    public function test_replayed_code_is_rejected(): void
    {
        $member = $this->makePendingMember();
        $this->makeOtp($member, '111222');

        $this->postJson('/api/v1/auth/verify/email', [
            'email' => $member->email,
            'code'  => '111222',
        ])->assertStatus(200);

        $response = $this->postJson('/api/v1/auth/verify/email', [
            'email' => $member->email,
            'code'  => '111222',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'A007']);
    }

    public function test_verify_for_unknown_email_returns_invalid_code(): void
    {
        $response = $this->postJson('/api/v1/auth/verify/email', [
            'email' => 'nobody@example.com',
            'code'  => '000000',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'A007']);
    }

    // ---- verify/email/send ----

    public function test_resend_sends_notification_for_existing_unverified_member(): void
    {
        Notification::fake();
        $member = $this->makePendingMember();

        $response = $this->postJson('/api/v1/auth/verify/email/send', [
            'email' => $member->email,
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'code' => 'S200']);

        Notification::assertSentTo($member, SendOtpNotification::class);
    }

    public function test_resend_for_already_verified_returns_conflict(): void
    {
        $member = $this->makePendingMember();
        $member->markEmailAsVerified();

        $response = $this->postJson('/api/v1/auth/verify/email/send', [
            'email' => $member->email,
        ]);

        $response->assertStatus(409)
                 ->assertJson(['code' => 'A006']);
    }

    public function test_resend_respects_cooldown(): void
    {
        $member = $this->makePendingMember();
        // 簡模 cooldown：剛建立一個 OTP（內部 isThrottled 會基於 created_at < 60s 判定）
        $this->makeOtp($member, '111222');

        $response = $this->postJson('/api/v1/auth/verify/email/send', [
            'email' => $member->email,
        ]);

        $response->assertStatus(429)
                 ->assertJson(['code' => 'A008']);
    }

    public function test_resend_for_unknown_email_returns_success_silently(): void
    {
        $response = $this->postJson('/api/v1/auth/verify/email/send', [
            'email' => 'nobody@example.com',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);
    }
}
