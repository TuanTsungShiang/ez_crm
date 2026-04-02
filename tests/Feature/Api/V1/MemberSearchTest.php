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

class MemberSearchTest extends TestCase
{
    use RefreshDatabase;

    private string $endpoint = '/api/v1/members/search';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->seedBaseData();
    }

    // ---- 基本結構 ----

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $response = $this->getJson($this->endpoint);

        $response->assertStatus(401);
    }

    public function test_returns_success_response_structure(): void
    {
        $response = $this->getJson($this->endpoint);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'data' => [
                         'items' => [['id', 'uuid', 'name', 'email', 'status', 'group', 'tags', 'has_sns', 'created_at']],
                         'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
                     ],
                 ])
                 ->assertJson(['success' => true]);
    }

    // ---- keyword 搜尋 ----

    public function test_keyword_search_by_name(): void
    {
        $response = $this->getJson("{$this->endpoint}?keyword=王小明");

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.pagination.total'));
        $this->assertEquals('王小明', $response->json('data.items.0.name'));
    }

    public function test_keyword_search_by_email(): void
    {
        $response = $this->getJson("{$this->endpoint}?keyword=ming@example.com");

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.pagination.total'));
    }

    public function test_keyword_no_match_returns_empty(): void
    {
        $response = $this->getJson("{$this->endpoint}?keyword=不存在的人");

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('data.pagination.total'));
    }

    // ---- status 篩選 ----

    public function test_filter_by_status_active(): void
    {
        $response = $this->getJson("{$this->endpoint}?status=1");

        $response->assertStatus(200);
        foreach ($response->json('data.items') as $item) {
            $this->assertEquals(1, $item['status']);
        }
    }

    public function test_filter_by_status_disabled(): void
    {
        $response = $this->getJson("{$this->endpoint}?status=0");

        $response->assertStatus(200);
        foreach ($response->json('data.items') as $item) {
            $this->assertEquals(0, $item['status']);
        }
    }

    // ---- has_sns 篩選 ----

    public function test_filter_has_sns_true(): void
    {
        $response = $this->getJson("{$this->endpoint}?has_sns=1");

        $response->assertStatus(200);
        foreach ($response->json('data.items') as $item) {
            $this->assertTrue($item['has_sns']);
        }
    }

    public function test_filter_has_sns_false(): void
    {
        $response = $this->getJson("{$this->endpoint}?has_sns=0");

        $response->assertStatus(200);
        foreach ($response->json('data.items') as $item) {
            $this->assertFalse($item['has_sns']);
        }
    }

    // ---- 分頁 ----

    public function test_pagination_per_page(): void
    {
        $response = $this->getJson("{$this->endpoint}?per_page=2");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.items'));
        $this->assertEquals(2, $response->json('data.pagination.per_page'));
    }

    public function test_pagination_second_page(): void
    {
        $response = $this->getJson("{$this->endpoint}?per_page=2&page=2");

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.pagination.current_page'));
    }

    // ---- Validation 422 ----

    public function test_validation_fails_when_per_page_exceeds_100(): void
    {
        $response = $this->getJson("{$this->endpoint}?per_page=999");

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'message' => 'Validation failed'])
                 ->assertJsonStructure(['errors' => ['per_page']]);
    }

    public function test_validation_fails_when_invalid_status(): void
    {
        $response = $this->getJson("{$this->endpoint}?status=9");

        $response->assertStatus(422)
                 ->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_validation_fails_when_created_to_before_created_from(): void
    {
        $response = $this->getJson("{$this->endpoint}?created_from=2026-03-01&created_to=2026-01-01");

        $response->assertStatus(422)
                 ->assertJsonStructure(['errors' => ['created_to']]);
    }

    // ---- 輔助：建立測試資料 ----

    public function test_filter_by_tag_ids_requires_members_to_match_all_tags(): void
    {
        $tagA = Tag::create(['name' => 'TagA', 'color' => '#111111']);
        $tagB = Tag::create(['name' => 'TagB', 'color' => '#222222']);

        $memberWithAllTags = Member::create([
            'uuid'     => Str::uuid(),
            'name'     => 'All Tags',
            'email'    => 'all-tags@example.com',
            'password' => bcrypt('password'),
            'status'   => 1,
        ]);

        $memberWithPartialTags = Member::create([
            'uuid'     => Str::uuid(),
            'name'     => 'Partial Tags',
            'email'    => 'partial-tags@example.com',
            'password' => bcrypt('password'),
            'status'   => 1,
        ]);

        $memberWithAllTags->tags()->attach([$tagA->id, $tagB->id]);
        $memberWithPartialTags->tags()->attach([$tagA->id]);

        $response = $this->getJson($this->endpoint.'?'.http_build_query([
            'tag_ids'  => [$tagA->id, $tagB->id],
            'per_page' => 15,
        ]));

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.pagination.total'));
        $this->assertEquals($memberWithAllTags->id, $response->json('data.items.0.id'));
    }

    public function test_filter_by_tag_ids_ignores_duplicate_values(): void
    {
        $tagA = Tag::create(['name' => 'DupTagA', 'color' => '#333333']);
        $tagB = Tag::create(['name' => 'DupTagB', 'color' => '#444444']);

        $member = Member::create([
            'uuid'     => Str::uuid(),
            'name'     => 'Duplicate Tag Match',
            'email'    => 'duplicate-tag-match@example.com',
            'password' => bcrypt('password'),
            'status'   => 1,
        ]);

        $member->tags()->attach([$tagA->id, $tagB->id]);

        $uniqueResponse = $this->getJson($this->endpoint.'?'.http_build_query([
            'tag_ids'  => [$tagA->id, $tagB->id],
            'per_page' => 15,
        ]));

        $duplicateResponse = $this->getJson($this->endpoint.'?'.http_build_query([
            'tag_ids'  => [$tagA->id, $tagA->id, $tagB->id],
            'per_page' => 15,
        ]));

        $uniqueResponse->assertStatus(200);
        $duplicateResponse->assertStatus(200);

        $this->assertEquals(
            $uniqueResponse->json('data.pagination.total'),
            $duplicateResponse->json('data.pagination.total')
        );
        $this->assertEquals(
            $uniqueResponse->json('data.items.0.id'),
            $duplicateResponse->json('data.items.0.id')
        );
    }

    private function seedBaseData(): void
    {
        $group = MemberGroup::create(['name' => '一般會員', 'sort_order' => 1]);
        $tag   = Tag::create(['name' => '潛力客', 'color' => '#3B82F6']);

        $members = [
            ['name' => '王小明', 'email' => 'ming@example.com',  'status' => 1, 'has_sns' => true],
            ['name' => '林美華', 'email' => 'hua@example.com',   'status' => 1, 'has_sns' => false],
            ['name' => '陳大偉', 'email' => 'david@example.com', 'status' => 0, 'has_sns' => false],
        ];

        foreach ($members as $data) {
            $member = Member::create([
                'uuid'            => Str::uuid(),
                'member_group_id' => $group->id,
                'name'            => $data['name'],
                'email'           => $data['email'],
                'password'        => bcrypt('password'),
                'status'          => $data['status'],
            ]);

            MemberProfile::create(['member_id' => $member->id, 'gender' => 1]);

            if ($data['has_sns']) {
                MemberSns::create([
                    'member_id'        => $member->id,
                    'provider'         => 'google',
                    'provider_user_id' => Str::random(20),
                ]);
            }

            $member->tags()->attach($tag->id);
        }
    }
}
