<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent baseline seeder for RBAC.
 *
 * Source of truth: this file. If you edit a role's permission set in
 * Filament UI on prod, re-running this seeder will overwrite it.
 * That is intentional — baseline roles should be code-managed.
 * Custom roles created via UI are untouched.
 *
 * To add a new baseline role: extend $this->matrix() and re-run.
 * To add `developer` (sees webhook payload, no user mgmt) or
 * `auditor` (viewer + access log) — add a new column here.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles/permissions so changes apply immediately
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach ($this->matrix() as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
        }

        $this->assignSuperAdminToBootstrapUser();
    }

    /**
     * Master list of permissions (resource.action).
     */
    private function permissions(): array
    {
        return [
            'panel.access',

            'member.view_any',
            'member.view',
            'member.create',
            'member.update',
            'member.delete',
            'member.restore',

            'member_group.view_any',
            'member_group.manage',

            'tag.view_any',
            'tag.manage',

            'webhook_subscription.view_any',
            'webhook_subscription.manage',

            'webhook_delivery.view_any',
            'webhook_delivery.retry',

            'webhook_event.view_any',

            'notification_delivery.view_any',

            'points.view',
            'points.manage',

            'user.view_any',
            'user.manage',

            'role.view_any',
            'role.manage',
        ];
    }

    /**
     * Role × Permission matrix.
     *
     * super_admin gets ALL permissions (also short-circuited via
     * Gate::before in AuthServiceProvider as a safety net).
     */
    private function matrix(): array
    {
        $all = $this->permissions();

        return [
            'super_admin' => $all,

            'admin' => [
                'panel.access',
                'member.view_any', 'member.view', 'member.create', 'member.update', 'member.delete', 'member.restore',
                'member_group.view_any', 'member_group.manage',
                'tag.view_any', 'tag.manage',
                'webhook_subscription.view_any', 'webhook_subscription.manage',
                'webhook_delivery.view_any', 'webhook_delivery.retry',
                'webhook_event.view_any',
                'notification_delivery.view_any',
                'points.view', 'points.manage',
            ],

            'customer_support' => [
                'panel.access',
                'member.view_any', 'member.view', 'member.update',
                'member_group.view_any',
                'tag.view_any',
                'points.view',
            ],

            'marketing' => [
                'panel.access',
                'member.view_any', 'member.view',
                'member_group.view_any',
                'tag.view_any', 'tag.manage',
                'webhook_subscription.view_any',
                'webhook_delivery.view_any',
                'webhook_event.view_any',
                'notification_delivery.view_any',
                'points.view', 'points.manage',
            ],

            'viewer' => [
                'panel.access',
                'member.view_any', 'member.view',
                'member_group.view_any',
                'tag.view_any',
                'webhook_subscription.view_any',
                'webhook_delivery.view_any',
                'webhook_event.view_any',
                'notification_delivery.view_any',
                'points.view',
            ],
        ];
    }

    /**
     * Bootstrap admin: ensure admin@ezcrm.local exists and has super_admin.
     * Idempotent — safe to re-run.
     */
    private function assignSuperAdminToBootstrapUser(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@ezcrm.local'],
            [
                'name'     => 'System Admin',
                'password' => Hash::make('password'), // change after first login
            ]
        );

        if (! $user->hasRole('super_admin')) {
            $user->assignRole('super_admin');
        }
    }
}
