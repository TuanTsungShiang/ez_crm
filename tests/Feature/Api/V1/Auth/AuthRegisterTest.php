<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Member;
use App\Models\MemberVerification;
use App\Notifications\Member\SendOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthRegisterTest extends TestCase
{
    use RefreshDatabase;

    private string $endpoint = '/api/v1/auth/register';

    private array $validPayload = [
        'name'                  => '王小明',
        'email'                 => 'register@example.com',
        'password'              => 'Test1234',
        'password_confirmation' => 'Test1234',
        'agree_terms'           => true,
    ];

    public function test_can_register_with_valid_data(): void
    {
        Notification::fake();

        $response = $this->postJson($this->endpoint, $this->validPayload);

        $response->assertStatus(201)
                 ->assertJson(['success' => true, 'code' => 'S201'])
                 ->assertJsonStructure(['data' => ['member_uuid', 'email', 'otp_expires_in']]);

        $this->assertDatabaseHas('members', [
            'email'  => 'register@example.com',
            'status' => Member::STATUS_PENDING,
        ]);

        $member = Member::where('email', 'register@example.com')->first();
        $this->assertNull($member->email_verified_at);
        $this->assertTrue($member->profile()->exists());

        Notification::assertSentTo($member, SendOtpNotification::class);

        $this->assertDatabaseHas('member_verifications', [
            'member_id' => $member->id,
            'type'      => MemberVerification::TYPE_EMAIL,
        ]);
    }

    public function test_duplicate_email_rejected(): void
    {
        Member::create([
            'uuid'     => '00000000-0000-0000-0000-000000000001',
            'name'     => 'Existing',
            'email'    => 'register@example.com',
            'password' => 'secret',
            'status'   => Member::STATUS_ACTIVE,
        ]);

        $response = $this->postJson($this->endpoint, $this->validPayload);

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'code' => 'V006'])
                 ->assertJsonValidationErrors(['email']);
    }

    public function test_missing_agree_terms_rejected(): void
    {
        $payload = $this->validPayload;
        unset($payload['agree_terms']);

        $response = $this->postJson($this->endpoint, $payload);

        $response->assertStatus(422)
                 ->assertJson(['success' => false])
                 ->assertJsonValidationErrors(['agree_terms']);
    }

    public function test_weak_password_rejected(): void
    {
        $response = $this->postJson($this->endpoint, array_merge($this->validPayload, [
            'password'              => 'nouppercase1',
            'password_confirmation' => 'nouppercase1',
        ]));

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['password']);
    }
}
