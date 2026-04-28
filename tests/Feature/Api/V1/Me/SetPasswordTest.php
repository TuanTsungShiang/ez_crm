<?php

namespace Tests\Feature\Api\V1\Me;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SetPasswordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper: create an OAuth-only member (password is Str::random
     * placeholder, password_set_at is null).
     */
    private function makeOAuthOnlyMember(): Member
    {
        return Member::create([
            'uuid'              => (string) Str::uuid(),
            'name'              => 'OAuth User',
            'email'             => 'oauth@example.com',
            'password'          => Str::random(60), // placeholder
            'password_set_at'   => null,
            'status'            => Member::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function makeRegularMember(): Member
    {
        return Member::create([
            'uuid'              => (string) Str::uuid(),
            'name'              => 'Regular User',
            'email'             => 'regular@example.com',
            'password'          => 'Test1234',
            'password_set_at'   => now(),
            'status'            => Member::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    /* -------------------- PUT /me/password rejects OAuth-only -------------------- */

    public function test_oauth_only_member_cannot_use_update_password_endpoint(): void
    {
        $member = $this->makeOAuthOnlyMember();
        Sanctum::actingAs($member, [], 'member');

        $response = $this->putJson('/api/v1/me/password', [
            'current_password'      => 'whatever-they-try',
            'password'              => 'NewPass99',
            'password_confirmation' => 'NewPass99',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'A009']);  // INVALID_CREDENTIALS

        // password_set_at should still be null
        $this->assertNull($member->fresh()->password_set_at);
    }

    /* -------------------- POST /me/password/set happy path -------------------- */

    public function test_oauth_only_member_can_set_password(): void
    {
        $member = $this->makeOAuthOnlyMember();
        Sanctum::actingAs($member, [], 'member');

        $response = $this->postJson('/api/v1/me/password/set', [
            'password'              => 'NewPass99',
            'password_confirmation' => 'NewPass99',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'code' => 'S200']);

        $fresh = $member->fresh();
        $this->assertNotNull($fresh->password_set_at);
        $this->assertTrue(Hash::check('NewPass99', $fresh->password));
    }

    /* -------------------- POST /me/password/set rejects regular members -------------------- */

    public function test_regular_member_cannot_use_set_password_endpoint(): void
    {
        $member = $this->makeRegularMember();
        Sanctum::actingAs($member, [], 'member');

        $response = $this->postJson('/api/v1/me/password/set', [
            'password'              => 'NewPass99',
            'password_confirmation' => 'NewPass99',
        ]);

        $response->assertStatus(403)
                 ->assertJson(['code' => 'A014']);  // PASSWORD_ALREADY_SET

        // Original password unchanged
        $this->assertTrue(Hash::check('Test1234', $member->fresh()->password));
    }

    /* -------------------- /me exposes has_local_password flag -------------------- */

    public function test_me_endpoint_exposes_has_local_password_flag_false_for_oauth_only(): void
    {
        $member = $this->makeOAuthOnlyMember();
        Sanctum::actingAs($member, [], 'member');

        $this->getJson('/api/v1/me')
             ->assertStatus(200)
             ->assertJsonPath('data.has_local_password', false);
    }

    public function test_me_endpoint_exposes_has_local_password_flag_true_after_set(): void
    {
        $member = $this->makeOAuthOnlyMember();
        Sanctum::actingAs($member, [], 'member');

        $this->postJson('/api/v1/me/password/set', [
            'password'              => 'NewPass99',
            'password_confirmation' => 'NewPass99',
        ])->assertStatus(200);

        $this->getJson('/api/v1/me')
             ->assertJsonPath('data.has_local_password', true);
    }

    /* -------------------- Validation -------------------- */

    public function test_set_password_validates_min_length_and_complexity(): void
    {
        $member = $this->makeOAuthOnlyMember();
        Sanctum::actingAs($member, [], 'member');

        // Too short
        $this->postJson('/api/v1/me/password/set', [
            'password'              => 'Short1',
            'password_confirmation' => 'Short1',
        ])->assertStatus(422);

        // No uppercase
        $this->postJson('/api/v1/me/password/set', [
            'password'              => 'lowercase99',
            'password_confirmation' => 'lowercase99',
        ])->assertStatus(422);

        // Mismatch
        $this->postJson('/api/v1/me/password/set', [
            'password'              => 'NewPass99',
            'password_confirmation' => 'NewPass11',
        ])->assertStatus(422);
    }
}
