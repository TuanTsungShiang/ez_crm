<?php

namespace Tests\Feature\Api\V1;

use App\Models\Member;
use App\Models\MemberGroup;
use App\Models\MemberProfile;
use App\Models\MemberSns;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    // ---- 成功 ----

    public function test_show_returns_full_member_detail(): void
    {
        $group = MemberGroup::create(['name' => 'VIP', 'sort_order' => 1]);
        $tag   = Tag::create(['name' => '潛力客', 'color' => '#3B82F6']);

        $member = Member::create([
            'uuid'            => Str::uuid(),
            'name'            => '王小明',
            'nickname'        => 'Ming',
            'email'           => 'ming@example.com',
            'phone'           => '0912345678',
            'status'          => 1,
            'member_group_id' => $group->id,
        ]);

        MemberProfile::create([
            'member_id' => $member->id,
            'gender'   => 1,
            'birthday' => '1990-05-15',
            'language' => 'zh-TW',
            'timezone' => 'Asia/Taipei',
        ]);

        MemberSns::create([
            'member_id'        => $member->id,
            'provider'         => 'google',
            'provider_user_id' => Str::random(20),
        ]);

        $member->tags()->attach($tag->id);

        $response = $this->getJson("/api/v1/members/{$member->uuid}");

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'code'    => 'S200',
                 ])
                 ->assertJsonStructure([
                     'success', 'code',
                     'data' => [
                         'uuid', 'name', 'nickname', 'email', 'phone', 'status',
                         'email_verified_at', 'phone_verified_at',
                         'group' => ['name'],
                         'tags', 'profile' => ['gender', 'birthday', 'language', 'timezone'],
                         'sns', 'has_sns', 'last_login_at', 'created_at', 'updated_at',
                     ],
                 ]);

        $this->assertEquals('王小明', $response->json('data.name'));
        $this->assertEquals('VIP', $response->json('data.group.name'));
        $this->assertEquals('潛力客', $response->json('data.tags.0.name'));
        $this->assertEquals(1, $response->json('data.profile.gender'));
        $this->assertEquals('1990-05-15', $response->json('data.profile.birthday'));
        $this->assertEquals('google', $response->json('data.sns.0.provider'));
        $this->assertTrue($response->json('data.has_sns'));
    }

    public function test_show_member_without_profile_returns_null_profile(): void
    {
        $member = Member::create([
            'uuid'   => Str::uuid(),
            'name'   => '無 Profile',
            'email'  => 'noprofile@example.com',
            'status' => 1,
        ]);

        $response = $this->getJson("/api/v1/members/{$member->uuid}");

        $response->assertStatus(200);
        $this->assertNull($response->json('data.profile'));
    }

    // ---- 404 ----

    public function test_show_returns_404_for_nonexistent_uuid(): void
    {
        $response = $this->getJson('/api/v1/members/' . Str::uuid());

        $response->assertStatus(404)
                 ->assertJson([
                     'success' => false,
                     'code'    => 'N001',
                 ]);
    }

    public function test_show_returns_404_for_soft_deleted_member(): void
    {
        $member = Member::create([
            'uuid'   => Str::uuid(),
            'name'   => '已刪除',
            'email'  => 'deleted@example.com',
            'status' => 1,
        ]);

        $member->delete();

        $response = $this->getJson("/api/v1/members/{$member->uuid}");

        $response->assertStatus(404)
                 ->assertJson(['code' => 'N001']);
    }

    // ---- 401 ----

    public function test_show_requires_authentication(): void
    {
        $member = Member::create([
            'uuid'   => Str::uuid(),
            'name'   => '未認證測試',
            'email'  => 'unauth@example.com',
            'status' => 1,
        ]);

        $this->app['auth']->forgetGuards();

        $response = $this->getJson("/api/v1/members/{$member->uuid}");

        $response->assertStatus(401)
                 ->assertJson([
                     'success' => false,
                     'code'    => 'A001',
                 ]);
    }
}
