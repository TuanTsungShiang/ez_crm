<?php

namespace Tests\Feature\Api\V1;

use App\Models\Member;
use App\Models\MemberGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GroupApiTest extends TestCase
{
    use RefreshDatabase;

    private string $endpoint = '/api/v1/groups';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    // ---- List ----

    public function test_list_returns_all_groups_sorted(): void
    {
        MemberGroup::create(['name' => 'VIP', 'sort_order' => 2]);
        MemberGroup::create(['name' => '一般會員', 'sort_order' => 1]);

        $response = $this->getJson($this->endpoint);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'code' => 'S200']);

        $this->assertEquals('一般會員', $response->json('data.0.name'));
        $this->assertEquals('VIP', $response->json('data.1.name'));
    }

    public function test_list_includes_member_count(): void
    {
        $group = MemberGroup::create(['name' => 'Test', 'sort_order' => 1]);
        Member::create(['uuid' => Str::uuid(), 'name' => 'A', 'email' => 'a@test.com', 'status' => 1, 'member_group_id' => $group->id]);

        $response = $this->getJson($this->endpoint);

        $this->assertEquals(1, $response->json('data.0.member_count'));
    }

    // ---- Create ----

    public function test_create_group(): void
    {
        $response = $this->postJson($this->endpoint, [
            'name'        => '金牌會員',
            'description' => '高消費客群',
            'sort_order'  => 3,
        ]);

        $response->assertStatus(201)
                 ->assertJson(['success' => true, 'code' => 'S201']);
        $this->assertEquals('金牌會員', $response->json('data.name'));
        $this->assertDatabaseHas('member_groups', ['name' => '金牌會員']);
    }

    public function test_create_fails_with_duplicate_name(): void
    {
        MemberGroup::create(['name' => '已存在', 'sort_order' => 1]);

        $response = $this->postJson($this->endpoint, ['name' => '已存在']);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'V006']);
    }

    public function test_create_fails_without_name(): void
    {
        $response = $this->postJson($this->endpoint, []);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'V001']);
    }

    // ---- Show ----

    public function test_show_group(): void
    {
        $group = MemberGroup::create(['name' => 'VIP', 'sort_order' => 1]);

        $response = $this->getJson("{$this->endpoint}/{$group->id}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'data' => ['name' => 'VIP']]);
    }

    public function test_show_returns_404(): void
    {
        $response = $this->getJson("{$this->endpoint}/9999");

        $response->assertStatus(404)
                 ->assertJson(['code' => 'N001']);
    }

    // ---- Update ----

    public function test_update_group(): void
    {
        $group = MemberGroup::create(['name' => '舊名', 'sort_order' => 1]);

        $response = $this->putJson("{$this->endpoint}/{$group->id}", [
            'name' => '新名',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('新名', $response->json('data.name'));
    }

    public function test_update_same_name_succeeds(): void
    {
        $group = MemberGroup::create(['name' => '保留', 'sort_order' => 1]);

        $response = $this->putJson("{$this->endpoint}/{$group->id}", [
            'name' => '保留',
        ]);

        $response->assertStatus(200);
    }

    public function test_update_fails_with_other_group_name(): void
    {
        MemberGroup::create(['name' => '佔用', 'sort_order' => 1]);
        $group = MemberGroup::create(['name' => '我的', 'sort_order' => 2]);

        $response = $this->putJson("{$this->endpoint}/{$group->id}", [
            'name' => '佔用',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'V006']);
    }

    // ---- Delete ----

    public function test_delete_empty_group(): void
    {
        $group = MemberGroup::create(['name' => '空群組', 'sort_order' => 1]);

        $response = $this->deleteJson("{$this->endpoint}/{$group->id}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'code' => 'S200']);
        $this->assertDatabaseMissing('member_groups', ['id' => $group->id]);
    }

    public function test_delete_fails_when_group_has_members(): void
    {
        $group = MemberGroup::create(['name' => '有人', 'sort_order' => 1]);
        Member::create(['uuid' => Str::uuid(), 'name' => 'M', 'email' => 'm@test.com', 'status' => 1, 'member_group_id' => $group->id]);

        $response = $this->deleteJson("{$this->endpoint}/{$group->id}");

        $response->assertStatus(422)
                 ->assertJson(['code' => 'V005']);
        $this->assertDatabaseHas('member_groups', ['id' => $group->id]);
    }

    // ---- Auth ----

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $response = $this->getJson($this->endpoint);

        $response->assertStatus(401)
                 ->assertJson(['code' => 'A001']);
    }
}
