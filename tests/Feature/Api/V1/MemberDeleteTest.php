<?php

namespace Tests\Feature\Api\V1;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberDeleteTest extends TestCase
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
            'uuid'   => Str::uuid(),
            'name'   => '測試會員',
            'email'  => 'delete' . uniqid() . '@example.com',
            'status' => 1,
        ], $attrs));
    }

    // ---- 成功軟刪除 ----

    public function test_soft_delete_returns_uuid_and_deleted_at(): void
    {
        $member = $this->makeMember();

        $response = $this->deleteJson("/api/v1/members/{$member->uuid}");

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'code'    => 'S200',
                 ])
                 ->assertJsonStructure([
                     'success', 'code',
                     'data' => ['uuid', 'deleted_at'],
                 ]);

        $this->assertEquals($member->uuid, $response->json('data.uuid'));
        $this->assertNotNull($response->json('data.deleted_at'));
    }

    public function test_deleted_member_has_deleted_at_in_database(): void
    {
        $member = $this->makeMember();

        $this->deleteJson("/api/v1/members/{$member->uuid}");

        $this->assertSoftDeleted('members', ['id' => $member->id]);
    }

    // ---- 刪除後對其他 API 的影響 ----

    public function test_deleted_member_does_not_appear_in_search(): void
    {
        $member = $this->makeMember(['name' => '將被刪除']);
        $this->deleteJson("/api/v1/members/{$member->uuid}");

        $response = $this->getJson('/api/v1/members?keyword=將被刪除');

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('data.pagination.total'));
    }

    public function test_deleted_member_show_returns_404(): void
    {
        $member = $this->makeMember();
        $this->deleteJson("/api/v1/members/{$member->uuid}");

        $response = $this->getJson("/api/v1/members/{$member->uuid}");

        $response->assertStatus(404)
                 ->assertJson(['code' => 'N001']);
    }

    public function test_deleting_already_deleted_member_returns_404(): void
    {
        $member = $this->makeMember();
        $this->deleteJson("/api/v1/members/{$member->uuid}");

        $response = $this->deleteJson("/api/v1/members/{$member->uuid}");

        $response->assertStatus(404)
                 ->assertJson(['code' => 'N001']);
    }

    // ---- 404 / 401 ----

    public function test_returns_404_for_nonexistent_uuid(): void
    {
        $response = $this->deleteJson('/api/v1/members/' . Str::uuid());

        $response->assertStatus(404)
                 ->assertJson(['code' => 'N001']);
    }

    public function test_requires_authentication(): void
    {
        $member = $this->makeMember();

        $this->app['auth']->forgetGuards();

        $response = $this->deleteJson("/api/v1/members/{$member->uuid}");

        $response->assertStatus(401)
                 ->assertJson(['code' => 'A001']);
    }
}
