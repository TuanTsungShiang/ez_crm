<?php

namespace Tests\Feature\Api\V1;

use App\Models\Member;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TagApiTest extends TestCase
{
    use RefreshDatabase;

    private string $endpoint = '/api/v1/tags';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    // ---- List ----

    public function test_list_returns_all_tags_sorted(): void
    {
        Tag::create(['name' => 'VIP', 'color' => '#F59E0B']);
        Tag::create(['name' => '活躍用戶', 'color' => '#10B981']);

        $response = $this->getJson($this->endpoint);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'code' => 'S200']);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_includes_member_count(): void
    {
        $tag = Tag::create(['name' => 'Test', 'color' => '#000000']);
        $member = Member::create(['uuid' => Str::uuid(), 'name' => 'A', 'email' => 'a@test.com', 'status' => 1]);
        $member->tags()->attach($tag->id);

        $response = $this->getJson($this->endpoint);

        $this->assertEquals(1, $response->json('data.0.member_count'));
    }

    // ---- Create ----

    public function test_create_tag(): void
    {
        $response = $this->postJson($this->endpoint, [
            'name'        => '潛力客',
            'color'       => '#3B82F6',
            'description' => '有轉換潛力的客戶',
        ]);

        $response->assertStatus(201)
                 ->assertJson(['success' => true, 'code' => 'S201']);
        $this->assertEquals('潛力客', $response->json('data.name'));
        $this->assertEquals('#3B82F6', $response->json('data.color'));
    }

    public function test_create_fails_with_duplicate_name(): void
    {
        Tag::create(['name' => '已存在', 'color' => '#000000']);

        $response = $this->postJson($this->endpoint, ['name' => '已存在']);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'V006']);
    }

    public function test_create_fails_without_name(): void
    {
        $response = $this->postJson($this->endpoint, ['color' => '#FF0000']);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'V001']);
    }

    public function test_create_fails_with_invalid_color(): void
    {
        $response = $this->postJson($this->endpoint, [
            'name'  => '測試',
            'color' => 'red',
        ]);

        $response->assertStatus(422);
    }

    // ---- Show ----

    public function test_show_tag(): void
    {
        $tag = Tag::create(['name' => 'VIP', 'color' => '#F59E0B']);

        $response = $this->getJson("{$this->endpoint}/{$tag->id}");

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

    public function test_update_tag(): void
    {
        $tag = Tag::create(['name' => '舊標', 'color' => '#000000']);

        $response = $this->putJson("{$this->endpoint}/{$tag->id}", [
            'name'  => '新標',
            'color' => '#FF5733',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('新標', $response->json('data.name'));
        $this->assertEquals('#FF5733', $response->json('data.color'));
    }

    public function test_update_same_name_succeeds(): void
    {
        $tag = Tag::create(['name' => '保留', 'color' => '#000000']);

        $response = $this->putJson("{$this->endpoint}/{$tag->id}", ['name' => '保留']);

        $response->assertStatus(200);
    }

    public function test_update_fails_with_other_tag_name(): void
    {
        Tag::create(['name' => '佔用', 'color' => '#000000']);
        $tag = Tag::create(['name' => '我的', 'color' => '#111111']);

        $response = $this->putJson("{$this->endpoint}/{$tag->id}", ['name' => '佔用']);

        $response->assertStatus(422)
                 ->assertJson(['code' => 'V006']);
    }

    // ---- Delete ----

    public function test_delete_unused_tag(): void
    {
        $tag = Tag::create(['name' => '空標', 'color' => '#000000']);

        $response = $this->deleteJson("{$this->endpoint}/{$tag->id}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'code' => 'S200']);
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_delete_fails_when_tag_has_members(): void
    {
        $tag = Tag::create(['name' => '使用中', 'color' => '#000000']);
        $member = Member::create(['uuid' => Str::uuid(), 'name' => 'M', 'email' => 'm@test.com', 'status' => 1]);
        $member->tags()->attach($tag->id);

        $response = $this->deleteJson("{$this->endpoint}/{$tag->id}");

        $response->assertStatus(422)
                 ->assertJson(['code' => 'V005']);
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
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
