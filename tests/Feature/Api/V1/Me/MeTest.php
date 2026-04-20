<?php

namespace Tests\Feature\Api\V1\Me;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveMember(array $overrides = []): Member
    {
        return Member::create(array_merge([
            'uuid'              => (string) Str::uuid(),
            'name'              => 'Me測試',
            'email'             => 'me@example.com',
            'password'          => 'Test1234',
            'status'            => Member::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ], $overrides));
    }

    // ---- GET /me ----

    public function test_get_me_requires_auth(): void
    {
        $this->getJson('/api/v1/me')
             ->assertStatus(401)
             ->assertJson(['code' => 'A001']);
    }

    public function test_get_me_returns_self_data(): void
    {
        $member = $this->makeActiveMember();
        Sanctum::actingAs($member, [], 'member');

        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'code'    => 'S200',
                     'data'    => [
                         'uuid'  => $member->uuid,
                         'email' => $member->email,
                     ],
                 ]);
    }

    // ---- PUT /me ----

    public function test_update_me_partial_update(): void
    {
        $member = $this->makeActiveMember();
        Sanctum::actingAs($member, [], 'member');

        $response = $this->putJson('/api/v1/me', [
            'nickname' => '小明',
            'phone'    => '0911000111',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'code' => 'S200']);

        $member->refresh();
        $this->assertSame('小明', $member->nickname);
        $this->assertSame('0911000111', $member->phone);
        $this->assertSame('Me測試', $member->name); // unchanged
    }

    public function test_update_me_phone_unique_ignore_self(): void
    {
        $member = $this->makeActiveMember(['phone' => '0912000000']);
        Sanctum::actingAs($member, [], 'member');

        $response = $this->putJson('/api/v1/me', ['phone' => '0912000000']);

        $response->assertStatus(200);
    }

    public function test_update_me_rejects_phone_used_by_other_member(): void
    {
        Member::create([
            'uuid'     => (string) Str::uuid(),
            'name'     => 'Other',
            'email'    => 'other@example.com',
            'phone'    => '0912999888',
            'password' => 'secret',
            'status'   => Member::STATUS_ACTIVE,
        ]);

        $me = $this->makeActiveMember();
        Sanctum::actingAs($me, [], 'member');

        $response = $this->putJson('/api/v1/me', ['phone' => '0912999888']);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'V006'])
                 ->assertJsonValidationErrors(['phone']);
    }

    // ---- PUT /me/password ----

    public function test_update_password_wrong_current_rejected(): void
    {
        $member = $this->makeActiveMember();
        Sanctum::actingAs($member, [], 'member');

        $response = $this->putJson('/api/v1/me/password', [
            'current_password'      => 'Wrong',
            'password'              => 'NewPass99',
            'password_confirmation' => 'NewPass99',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'A009']);

        $this->assertTrue(Hash::check('Test1234', $member->fresh()->password));
    }

    public function test_update_password_same_as_current_rejected(): void
    {
        $member = $this->makeActiveMember();
        Sanctum::actingAs($member, [], 'member');

        $response = $this->putJson('/api/v1/me/password', [
            'current_password'      => 'Test1234',
            'password'              => 'Test1234',
            'password_confirmation' => 'Test1234',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['password']);
    }

    public function test_update_password_success_revokes_other_tokens(): void
    {
        $member = $this->makeActiveMember();
        // 3 old tokens from other devices
        $member->createToken('old-phone');
        $member->createToken('old-ipad');
        $member->createToken('old-desktop');
        $this->assertCount(3, $member->fresh()->tokens);

        Sanctum::actingAs($member, [], 'member');

        $response = $this->putJson('/api/v1/me/password', [
            'current_password'      => 'Test1234',
            'password'              => 'NewPass99',
            'password_confirmation' => 'NewPass99',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);

        $this->assertTrue(Hash::check('NewPass99', $member->fresh()->password));
        // currentAccessToken (Sanctum::actingAs) 是 TransientToken，不計入 DB tokens，
        // 所以舊的 3 筆 PersonalAccessToken 應被全部清除
        $this->assertCount(0, $member->fresh()->tokens);
    }
}
