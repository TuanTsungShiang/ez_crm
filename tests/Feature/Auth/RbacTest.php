<?php

namespace Tests\Feature\Auth;

use App\Models\Member;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(?string $role = null): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    /* -------------------- panel.access -------------------- */

    public function test_user_without_role_cannot_access_panel(): void
    {
        $user = $this->userWithRole(null);
        $panel = $this->mockPanel();

        $this->assertFalse($user->canAccessPanel($panel));
    }

    public function test_each_seeded_role_can_access_panel(): void
    {
        foreach (['super_admin', 'admin', 'customer_support', 'marketing', 'viewer'] as $role) {
            $user = $this->userWithRole($role);
            $this->assertTrue(
                $user->canAccessPanel($this->mockPanel()),
                "{$role} should have panel.access"
            );
        }
    }

    /* -------------------- super_admin Gate::before short-circuit -------------------- */

    public function test_super_admin_bypasses_all_permission_checks(): void
    {
        $user = $this->userWithRole('super_admin');
        $this->actingAs($user);

        // super_admin should be allowed even for permissions that don't exist
        $this->assertTrue($user->can('member.delete'));
        $this->assertTrue($user->can('user.manage'));
        $this->assertTrue($user->can('this.permission.does.not.exist'));
    }

    /* -------------------- admin: business full, no user/role mgmt -------------------- */

    public function test_admin_can_manage_business_resources(): void
    {
        $user = $this->userWithRole('admin');
        $this->actingAs($user);

        $this->assertTrue($user->can('member.create'));
        $this->assertTrue($user->can('member.delete'));
        $this->assertTrue($user->can('webhook_subscription.manage'));
        $this->assertTrue($user->can('webhook_delivery.retry'));
    }

    public function test_admin_cannot_manage_users_or_roles(): void
    {
        $user = $this->userWithRole('admin');
        $this->actingAs($user);

        $this->assertFalse($user->can('user.view_any'));
        $this->assertFalse($user->can('user.manage'));
        $this->assertFalse($user->can('role.manage'));
    }

    /* -------------------- customer_support: view + update member only -------------------- */

    public function test_customer_support_can_view_and_update_member_but_not_create_or_delete(): void
    {
        $user = $this->userWithRole('customer_support');
        $this->actingAs($user);

        $this->assertTrue($user->can('member.view_any'));
        $this->assertTrue($user->can('member.view'));
        $this->assertTrue($user->can('member.update'));

        $this->assertFalse($user->can('member.create'));
        $this->assertFalse($user->can('member.delete'));
        $this->assertFalse($user->can('webhook_subscription.view_any'));
    }

    /* -------------------- marketing: tag.manage but not member.update -------------------- */

    public function test_marketing_can_manage_tag_but_not_update_member(): void
    {
        $user = $this->userWithRole('marketing');
        $this->actingAs($user);

        $this->assertTrue($user->can('tag.manage'));
        $this->assertTrue($user->can('webhook_delivery.view_any'));
        $this->assertTrue($user->can('notification_delivery.view_any'));

        $this->assertFalse($user->can('member.update'));
        $this->assertFalse($user->can('member.delete'));
        $this->assertFalse($user->can('webhook_subscription.manage'));
        $this->assertFalse($user->can('webhook_delivery.retry'));
    }

    /* -------------------- viewer: read-only -------------------- */

    public function test_viewer_has_no_write_permissions(): void
    {
        $user = $this->userWithRole('viewer');
        $this->actingAs($user);

        // can read
        $this->assertTrue($user->can('member.view_any'));
        $this->assertTrue($user->can('webhook_delivery.view_any'));

        // cannot write anything
        $this->assertFalse($user->can('member.create'));
        $this->assertFalse($user->can('member.update'));
        $this->assertFalse($user->can('member.delete'));
        $this->assertFalse($user->can('tag.manage'));
        $this->assertFalse($user->can('webhook_subscription.manage'));
        $this->assertFalse($user->can('webhook_delivery.retry'));
    }

    /* -------------------- Policy classes resolve through Gate -------------------- */

    public function test_member_policy_respects_role(): void
    {
        $support = $this->userWithRole('customer_support');
        // Unsaved instance — policies only check user permission, not model state
        $member = new Member();

        $this->assertTrue($support->can('view', $member));
        $this->assertTrue($support->can('update', $member));
        $this->assertFalse($support->can('delete', $member));
    }

    public function test_tag_policy_marketing_can_manage_viewer_cannot(): void
    {
        $marketing = $this->userWithRole('marketing');
        $viewer = $this->userWithRole('viewer');
        $tag = new Tag();

        $this->assertTrue($marketing->can('update', $tag));
        $this->assertTrue($marketing->can('delete', $tag));

        $this->assertFalse($viewer->can('update', $tag));
        $this->assertFalse($viewer->can('delete', $tag));
    }

    /* -------------------- UserPolicy: cannot delete self (non-super_admin) -------------------- */

    /**
     * Note: super_admin bypasses Policy via Gate::before by design.
     * Self-delete protection for super_admin is enforced at the Filament UI
     * layer (Action ->visible). See backlog.
     */
    public function test_user_with_user_manage_cannot_delete_themselves(): void
    {
        $role = \Spatie\Permission\Models\Role::create([
            'name'       => 'user_manager_test',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo('user.manage');

        $admin = $this->userWithRole('user_manager_test');
        $this->actingAs($admin);

        $this->assertFalse($admin->can('delete', $admin));
    }

    public function test_user_with_user_manage_can_delete_others(): void
    {
        $role = \Spatie\Permission\Models\Role::create([
            'name'       => 'user_manager_test',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo('user.manage');

        $admin = $this->userWithRole('user_manager_test');
        $other = $this->userWithRole(null);
        $this->actingAs($admin);

        $this->assertTrue($admin->can('delete', $other));
    }

    /* -------------------- RolePolicy: baseline roles cannot be deleted (non-super_admin) -------------------- */

    public function test_user_with_role_manage_cannot_delete_baseline_roles(): void
    {
        $role = \Spatie\Permission\Models\Role::create([
            'name'       => 'role_manager_test',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo('role.manage');

        $admin = $this->userWithRole('role_manager_test');
        $this->actingAs($admin);

        foreach (['super_admin', 'admin', 'customer_support', 'marketing', 'viewer'] as $name) {
            $r = \Spatie\Permission\Models\Role::where('name', $name)->first();
            $this->assertFalse(
                $admin->can('delete', $r),
                "baseline role {$name} should not be deletable"
            );
        }
    }

    public function test_user_with_role_manage_can_delete_custom_roles(): void
    {
        $managerRole = \Spatie\Permission\Models\Role::create([
            'name'       => 'role_manager_test',
            'guard_name' => 'web',
        ]);
        $managerRole->givePermissionTo('role.manage');

        $admin = $this->userWithRole('role_manager_test');
        $this->actingAs($admin);

        $custom = \Spatie\Permission\Models\Role::create([
            'name'       => 'developer_custom',
            'guard_name' => 'web',
        ]);

        $this->assertTrue($admin->can('delete', $custom));
    }

    /* -------------------- Widget visibility -------------------- */

    public function test_webhook_health_widget_visible_to_roles_with_webhook_delivery_view_any(): void
    {
        foreach (['super_admin', 'admin', 'marketing', 'viewer'] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user);
            $this->assertTrue(
                \App\Filament\Widgets\WebhookHealthWidget::canView(),
                "{$role} should see WebhookHealthWidget"
            );
        }
    }

    public function test_webhook_health_widget_hidden_from_customer_support(): void
    {
        $user = $this->userWithRole('customer_support');
        $this->actingAs($user);

        $this->assertFalse(\App\Filament\Widgets\WebhookHealthWidget::canView());
    }

    public function test_webhook_health_widget_hidden_from_guest(): void
    {
        // No actingAs — auth()->user() is null
        $this->assertFalse(\App\Filament\Widgets\WebhookHealthWidget::canView());
    }

    /* -------------------- Bootstrap admin sanity check -------------------- */

    public function test_bootstrap_admin_is_seeded_with_super_admin_role(): void
    {
        $admin = User::where('email', 'admin@ezcrm.local')->first();

        $this->assertNotNull($admin, 'admin@ezcrm.local should exist after seeder');
        $this->assertTrue($admin->hasRole('super_admin'));
        $this->assertTrue($admin->canAccessPanel($this->mockPanel()));
    }

    private function mockPanel(): Panel
    {
        return new Panel();
    }
}
