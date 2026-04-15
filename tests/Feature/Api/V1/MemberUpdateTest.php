<?php

namespace Tests\Feature\Api\V1;

use App\Models\Member;
use App\Models\MemberGroup;
use App\Models\MemberProfile;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function makeMember(array $attrs = []): Member
    {
        return Member::create(array_merge([
            'uuid'     => Str::uuid(),
            'name'     => '原始姓名',
            'email'    => 'original' . uniqid() . '@example.com',
            'password' => bcrypt('oldpassword'),
            'status'   => 1,
        ], $attrs));
    }

    // ---- Partial Update ----

    public function test_update_single_field(): void
    {
        $member = $this->makeMember(['name' => '舊名']);

        $response = $this->putJson("/api/v1/members/{$member->uuid}", [
            'name' => '新名',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'code' => 'S200']);

        $this->assertEquals('新名', $response->json('data.name'));
        $this->assertEquals($member->email, $response->json('data.email'));
    }

    public function test_untouched_fields_remain_unchanged(): void
    {
        $member = $this->makeMember([
            'nickname' => '小明',
            'email'    => 'keep@example.com',
            'phone'    => '0911111111',
        ]);

        $this->putJson("/api/v1/members/{$member->uuid}", [
            'name' => '只改名',
        ]);

        $member->refresh();
        $this->assertEquals('小明', $member->nickname);
        $this->assertEquals('keep@example.com', $member->email);
        $this->assertEquals('0911111111', $member->phone);
    }

    // ---- Profile partial update ----

    public function test_profile_partial_update_keeps_other_fields(): void
    {
        $member = $this->makeMember();
        MemberProfile::create([
            'member_id' => $member->id,
            'gender'    => 1,
            'birthday'  => '1990-01-01',
            'language'  => 'zh-TW',
            'timezone'  => 'Asia/Taipei',
        ]);

        $this->putJson("/api/v1/members/{$member->uuid}", [
            'profile' => ['gender' => 2],
        ]);

        $profile = $member->profile()->first();
        $this->assertEquals(2, $profile->gender);
        $this->assertEquals('1990-01-01', $profile->birthday->format('Y-m-d'));
        $this->assertEquals('zh-TW', $profile->language);
    }

    public function test_profile_created_when_not_exists(): void
    {
        $member = $this->makeMember();

        $this->putJson("/api/v1/members/{$member->uuid}", [
            'profile' => ['gender' => 1, 'birthday' => '1995-03-20'],
        ]);

        $this->assertNotNull($member->profile()->first());
        $this->assertEquals(1, $member->profile()->first()->gender);
    }

    // ---- Tag sync ----

    public function test_tag_ids_sync_replaces_all(): void
    {
        $member = $this->makeMember();
        $t1 = Tag::create(['name' => 'T1', 'color' => '#111']);
        $t2 = Tag::create(['name' => 'T2', 'color' => '#222']);
        $t3 = Tag::create(['name' => 'T3', 'color' => '#333']);
        $t4 = Tag::create(['name' => 'T4', 'color' => '#444']);

        $member->tags()->attach([$t1->id, $t2->id, $t3->id]);

        $this->putJson("/api/v1/members/{$member->uuid}", [
            'tag_ids' => [$t2->id, $t4->id],
        ]);

        $currentTagIds = $member->tags()->pluck('tags.id')->sort()->values()->toArray();
        $this->assertEquals([$t2->id, $t4->id], $currentTagIds);
    }

    public function test_tag_ids_empty_array_clears_all(): void
    {
        $member = $this->makeMember();
        $t1 = Tag::create(['name' => 'X1', 'color' => '#111']);
        $member->tags()->attach([$t1->id]);

        $this->putJson("/api/v1/members/{$member->uuid}", [
            'tag_ids' => [],
        ]);

        $this->assertEquals(0, $member->tags()->count());
    }

    public function test_tag_ids_not_passed_keeps_tags(): void
    {
        $member = $this->makeMember();
        $t1 = Tag::create(['name' => 'Y1', 'color' => '#111']);
        $member->tags()->attach([$t1->id]);

        $this->putJson("/api/v1/members/{$member->uuid}", [
            'name' => '只改名不動 tag',
        ]);

        $this->assertEquals(1, $member->tags()->count());
    }

    // ---- Unique 排除自己 ----

    public function test_fails_when_email_taken_by_another(): void
    {
        $other = $this->makeMember(['email' => 'taken@example.com']);
        $me    = $this->makeMember(['email' => 'me@example.com']);

        $response = $this->putJson("/api/v1/members/{$me->uuid}", [
            'email' => 'taken@example.com',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'V006']);
    }

    public function test_updating_to_same_email_succeeds(): void
    {
        $member = $this->makeMember(['email' => 'same@example.com']);

        $response = $this->putJson("/api/v1/members/{$member->uuid}", [
            'email' => 'same@example.com',
        ]);

        $response->assertStatus(200);
    }

    // ---- KYC: verified_at auto-clear ----

    public function test_email_change_clears_email_verified_at(): void
    {
        $member = $this->makeMember([
            'email'             => 'old@example.com',
            'email_verified_at' => now(),
        ]);

        $this->assertNotNull($member->email_verified_at);

        $this->putJson("/api/v1/members/{$member->uuid}", [
            'email' => 'new@example.com',
        ]);

        $this->assertNull($member->fresh()->email_verified_at);
    }

    public function test_phone_change_clears_phone_verified_at(): void
    {
        $member = $this->makeMember([
            'phone'             => '0911111111',
            'phone_verified_at' => now(),
        ]);

        $this->putJson("/api/v1/members/{$member->uuid}", [
            'phone' => '0922222222',
        ]);

        $this->assertNull($member->fresh()->phone_verified_at);
    }

    public function test_email_unchanged_keeps_verified_at(): void
    {
        $member = $this->makeMember([
            'email'             => 'stable@example.com',
            'email_verified_at' => now(),
        ]);

        $this->putJson("/api/v1/members/{$member->uuid}", [
            'name'  => '只改名',
            'email' => 'stable@example.com',
        ]);

        $this->assertNotNull($member->fresh()->email_verified_at);
    }

    // ---- Password ----

    public function test_password_updated_is_hashed(): void
    {
        $member = $this->makeMember();

        $this->putJson("/api/v1/members/{$member->uuid}", [
            'password' => 'brandnewpass',
        ]);

        $fresh = $member->fresh();
        $this->assertTrue(Hash::check('brandnewpass', $fresh->password));
    }

    public function test_password_not_passed_keeps_old(): void
    {
        $member = $this->makeMember();
        $original = $member->password;

        $this->putJson("/api/v1/members/{$member->uuid}", [
            'name' => '不改密碼',
        ]);

        $this->assertEquals($original, $member->fresh()->password);
    }

    // ---- group_id ----

    public function test_group_id_nullable_removes_group(): void
    {
        $group = MemberGroup::create(['name' => 'A', 'sort_order' => 1]);
        $member = $this->makeMember(['member_group_id' => $group->id]);

        $this->putJson("/api/v1/members/{$member->uuid}", [
            'group_id' => null,
        ]);

        $this->assertNull($member->fresh()->member_group_id);
    }

    public function test_fails_with_invalid_group_id(): void
    {
        $member = $this->makeMember();

        $response = $this->putJson("/api/v1/members/{$member->uuid}", [
            'group_id' => 9999,
        ]);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'V005']);
    }

    // ---- 404 / 401 ----

    public function test_returns_404_for_nonexistent_member(): void
    {
        $response = $this->putJson('/api/v1/members/' . Str::uuid(), [
            'name' => '任意',
        ]);

        $response->assertStatus(404)
                 ->assertJson(['code' => 'N001']);
    }

    public function test_requires_authentication(): void
    {
        $member = $this->makeMember();

        $this->app['auth']->forgetGuards();

        $response = $this->putJson("/api/v1/members/{$member->uuid}", [
            'name' => '未認證',
        ]);

        $response->assertStatus(401)
                 ->assertJson(['code' => 'A001']);
    }
}
