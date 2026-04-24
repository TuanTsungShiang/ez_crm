<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Member;
use App\Models\MemberVerification;
use App\Models\NotificationDelivery;
use App\Services\Sms\Drivers\NullDriver;
use App\Services\Sms\SmsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhoneOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 避免 NullDriver 的亂數 provider_message_id 影響觀察
        $this->app->make(SmsManager::class)->setDriver(new NullDriver());
    }

    private function makeMember(string $phone, bool $phoneVerified = false): Member
    {
        return Member::create([
            'uuid'              => (string) Str::uuid(),
            'name'              => 'Phone Test',
            'email'             => 'phone-' . Str::random(6) . '@example.com',
            'phone'             => $phone,
            'password'          => 'Test1234',
            'status'            => Member::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'phone_verified_at' => $phoneVerified ? now() : null,
        ]);
    }

    // ---- send ----

    public function test_send_creates_verification_and_delivery_row(): void
    {
        $member = $this->makeMember('0912345678');

        $this->postJson('/api/v1/auth/verify/phone/send', [
            'phone' => '0912345678',
        ])->assertStatus(200)->assertJson(['success' => true, 'code' => 'S200']);

        $this->assertDatabaseHas('member_verifications', [
            'member_id' => $member->id,
            'type'      => MemberVerification::TYPE_PHONE,
        ]);

        $delivery = NotificationDelivery::where('member_id', $member->id)->first();
        $this->assertNotNull($delivery);
        $this->assertSame('sms', $delivery->channel);
        $this->assertSame('null', $delivery->driver);
        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame(NotificationDelivery::PURPOSE_OTP_VERIFY, $delivery->purpose);
        $this->assertStringContainsString('驗證碼', $delivery->content);
    }

    public function test_send_normalizes_phone_with_spaces_and_dashes(): void
    {
        $member = $this->makeMember('0912345678');

        $this->postJson('/api/v1/auth/verify/phone/send', [
            'phone' => '0912-345 678',
        ])->assertStatus(200);

        $this->assertDatabaseHas('member_verifications', [
            'member_id' => $member->id,
            'type'      => MemberVerification::TYPE_PHONE,
        ]);
    }

    public function test_send_silently_succeeds_when_phone_unknown(): void
    {
        $this->postJson('/api/v1/auth/verify/phone/send', [
            'phone' => '0900000000',
        ])->assertStatus(200)->assertJson(['success' => true]);

        // 沒 member → 不該建 verification / delivery
        $this->assertDatabaseCount('member_verifications', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_send_rejects_already_verified_phone(): void
    {
        $this->makeMember('0912345678', phoneVerified: true);

        $this->postJson('/api/v1/auth/verify/phone/send', [
            'phone' => '0912345678',
        ])->assertStatus(409)->assertJson(['code' => 'A006']);
    }

    public function test_send_respects_cooldown_throttle(): void
    {
        $this->makeMember('0912345678');

        $this->postJson('/api/v1/auth/verify/phone/send', ['phone' => '0912345678'])
             ->assertStatus(200);

        $this->postJson('/api/v1/auth/verify/phone/send', ['phone' => '0912345678'])
             ->assertStatus(429)->assertJson(['code' => 'A008']);
    }

    // ---- verify ----

    public function test_verify_with_valid_code_marks_phone_verified(): void
    {
        $member = $this->makeMember('0912345678');
        $this->postJson('/api/v1/auth/verify/phone/send', ['phone' => '0912345678']);

        $code = MemberVerification::where('member_id', $member->id)
            ->where('type', MemberVerification::TYPE_PHONE)
            ->latest('id')->first()->token;

        $this->postJson('/api/v1/auth/verify/phone', [
            'phone' => '0912345678',
            'code'  => $code,
        ])->assertStatus(200)
          ->assertJson(['success' => true, 'data' => ['phone' => '0912345678']]);

        $this->assertNotNull($member->fresh()->phone_verified_at);
    }

    public function test_verify_rejects_wrong_code(): void
    {
        $member = $this->makeMember('0912345678');
        $this->postJson('/api/v1/auth/verify/phone/send', ['phone' => '0912345678']);

        $this->postJson('/api/v1/auth/verify/phone', [
            'phone' => '0912345678',
            'code'  => '000000',
        ])->assertStatus(422)->assertJson(['code' => 'A007']);

        $this->assertNull($member->fresh()->phone_verified_at);
    }

    public function test_verify_for_unknown_phone_returns_invalid_code(): void
    {
        $this->postJson('/api/v1/auth/verify/phone', [
            'phone' => '0900000000',
            'code'  => '123456',
        ])->assertStatus(422)->assertJson(['code' => 'A007']);
    }

    public function test_verify_replay_is_rejected(): void
    {
        $member = $this->makeMember('0912345678');
        $this->postJson('/api/v1/auth/verify/phone/send', ['phone' => '0912345678']);

        $code = MemberVerification::where('member_id', $member->id)
            ->where('type', MemberVerification::TYPE_PHONE)
            ->latest('id')->first()->token;

        $this->postJson('/api/v1/auth/verify/phone', ['phone' => '0912345678', 'code' => $code])
             ->assertStatus(200);

        $this->postJson('/api/v1/auth/verify/phone', ['phone' => '0912345678', 'code' => $code])
             ->assertStatus(422)->assertJson(['code' => 'A007']);
    }

    public function test_log_driver_actually_writes_to_log(): void
    {
        // 換成真正的 LogDriver 驗證一次
        $this->app->make(SmsManager::class)->setDriver(
            $this->app->make(\App\Services\Sms\Drivers\LogDriver::class),
        );

        $this->makeMember('0912345678');

        Log::spy();
        $this->postJson('/api/v1/auth/verify/phone/send', ['phone' => '0912345678'])
             ->assertStatus(200);

        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context) {
                return $message === '[SMS:log] delivered'
                    && $context['to'] === '0912345678'
                    && $context['purpose'] === NotificationDelivery::PURPOSE_OTP_VERIFY;
            })->once();
    }
}
