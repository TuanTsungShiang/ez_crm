<?php

namespace Tests\Feature\Api\V1;

use App\Models\Member;
use App\Models\MemberGroup;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberCreateTest extends TestCase
{
    use RefreshDatabase;

    private string $endpoint = '/api/v1/members';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    // ---- 成功建立 ----

    public function test_create_member_with_full_fields(): void
    {
        $group = MemberGroup::create(['name' => '一般會員', 'sort_order' => 1]);
        $tag = Tag::create(['name' => '潛力客', 'color' => '#3B82F6']);

        $response = $this->postJson($this->endpoint, [
            'name'     => '王小明',
            'nickname' => 'Ming',
            'email'    => 'ming@example.com',
            'phone'    => '0912345678',
            'password' => 'password123',
            'status'   => 1,
            'group_id' => $group->id,
            'tag_ids'  => [$tag->id],
            'profile'  => [
                'gender'   => 1,
                'birthday' => '1990-05-15',
            ],
        ]);

        $response->assertStatus(201)
                 ->assertJson([
                     'success' => true,
                     'code'    => 'S201',
                 ])
                 ->assertJsonStructure([
                     'success', 'code', 'data' => [
                         'uuid', 'name', 'nickname', 'email', 'phone',
                         'status', 'group', 'tags', 'has_sns', 'created_at',
                     ],
                 ]);

        $this->assertEquals('王小明', $response->json('data.name'));
        $this->assertEquals('一般會員', $response->json('data.group.name'));
        $this->assertEquals('潛力客', $response->json('data.tags.0.name'));

        $this->assertDatabaseHas('members', ['email' => 'ming@example.com']);
        $this->assertDatabaseHas('member_profiles', [
            'gender'   => 1,
            'birthday' => '1990-05-15',
        ]);
    }

    public function test_create_member_with_email_only(): void
    {
        $response = $this->postJson($this->endpoint, [
            'name'  => '林美華',
            'email' => 'hua@example.com',
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.phone'));
    }

    public function test_create_member_with_phone_only(): void
    {
        $response = $this->postJson($this->endpoint, [
            'name'  => '陳大偉',
            'phone' => '0987654321',
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.email'));
    }

    public function test_default_status_is_active(): void
    {
        $response = $this->postJson($this->endpoint, [
            'name'  => '張雅婷',
            'email' => 'ting@example.com',
        ]);

        $response->assertStatus(201);
        $this->assertEquals(1, $response->json('data.status'));
    }

    public function test_profile_is_created_even_without_profile_fields(): void
    {
        $response = $this->postJson($this->endpoint, [
            'name'  => '李志豪',
            'email' => 'hao@example.com',
        ]);

        $response->assertStatus(201);

        $member = Member::where('email', 'hao@example.com')->first();
        $this->assertNotNull($member->profile);
    }

    // ---- 驗證失敗 ----

    public function test_fails_without_name(): void
    {
        $response = $this->postJson($this->endpoint, [
            'email' => 'noname@example.com',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'code' => 'V001'])
                 ->assertJsonStructure(['errors' => ['name']]);
    }

    public function test_fails_without_email_and_phone(): void
    {
        $response = $this->postJson($this->endpoint, [
            'name' => '無聯絡方式',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'code' => 'V001']);
    }

    public function test_fails_with_duplicate_email(): void
    {
        Member::create([
            'uuid'   => \Illuminate\Support\Str::uuid(),
            'name'   => '既有會員',
            'email'  => 'taken@example.com',
            'status' => 1,
        ]);

        $response = $this->postJson($this->endpoint, [
            'name'  => '新會員',
            'email' => 'taken@example.com',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'code' => 'V006']);
    }

    public function test_fails_with_duplicate_phone(): void
    {
        Member::create([
            'uuid'   => \Illuminate\Support\Str::uuid(),
            'name'   => '既有會員',
            'phone'  => '0911111111',
            'status' => 1,
        ]);

        $response = $this->postJson($this->endpoint, [
            'name'  => '新會員',
            'phone' => '0911111111',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'code' => 'V006']);
    }

    public function test_fails_with_invalid_group_id(): void
    {
        $response = $this->postJson($this->endpoint, [
            'name'     => '測試會員',
            'email'    => 'test@example.com',
            'group_id' => 9999,
        ]);

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'code' => 'V005']);
    }

    public function test_fails_with_invalid_tag_ids(): void
    {
        $response = $this->postJson($this->endpoint, [
            'name'    => '測試會員',
            'email'   => 'test@example.com',
            'tag_ids' => [9999],
        ]);

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'code' => 'V005']);
    }

    // ---- 認證 ----

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $response = $this->postJson($this->endpoint, [
            'name'  => '未認證',
            'email' => 'unauth@example.com',
        ]);

        $response->assertStatus(401)
                 ->assertJson(['success' => false, 'code' => 'A001']);
    }
}
