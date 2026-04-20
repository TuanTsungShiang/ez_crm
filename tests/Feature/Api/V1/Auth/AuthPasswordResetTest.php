<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Member;
use App\Models\MemberVerification;
use App\Notifications\Member\SendOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function makeMember(): Member
    {
        return Member::create([
            'uuid'              => (string) Str::uuid(),
            'name'              => 'PwReset',
            'email'             => 'pw@example.com',
            'password'          => 'OldPass1',
            'status'            => Member::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    // ---- password/forgot ----

    public function test_forgot_sends_notification_for_existing_email(): void
    {
        Notification::fake();
        $member = $this->makeMember();

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'pw@example.com',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);

        Notification::assertSentTo($member, SendOtpNotification::class);
        $this->assertDatabaseHas('member_verifications', [
            'member_id' => $member->id,
            'type'      => MemberVerification::TYPE_PASSWORD_RESET,
        ]);
    }

    public function test_forgot_for_unknown_email_returns_success_silently(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'nobody@example.com',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);

        Notification::assertNothingSent();
    }

    // ---- password/reset ----

    public function test_reset_with_valid_code_updates_password_and_revokes_tokens(): void
    {
        $member = $this->makeMember();
        $member->createToken('old-device-1');
        $member->createToken('old-device-2');
        $this->assertCount(2, $member->fresh()->tokens);

        MemberVerification::create([
            'member_id'  => $member->id,
            'type'       => MemberVerification::TYPE_PASSWORD_RESET,
            'token'      => '888888',
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'email'                 => 'pw@example.com',
            'code'                  => '888888',
            'password'              => 'NewPass99',
            'password_confirmation' => 'NewPass99',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);

        $this->assertTrue(Hash::check('NewPass99', $member->fresh()->password));
        $this->assertCount(0, $member->fresh()->tokens);
    }

    public function test_reset_with_invalid_code_rejected(): void
    {
        $member = $this->makeMember();
        MemberVerification::create([
            'member_id'  => $member->id,
            'type'       => MemberVerification::TYPE_PASSWORD_RESET,
            'token'      => '888888',
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'email'                 => 'pw@example.com',
            'code'                  => '000000',
            'password'              => 'NewPass99',
            'password_confirmation' => 'NewPass99',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'A007']);

        $this->assertTrue(Hash::check('OldPass1', $member->fresh()->password));
    }
}
