<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roles decide what somebody may reach. The catalogue of permissions is code;
 * which roles hold which is data, edited from the roles page.
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * Somebody on a role of their own, holding exactly what is passed.
     *
     * @param  array<int, Permission>  $permissions
     */
    private function staffWith(array $permissions = [], string $name = 'Department head'): User
    {
        $role = Role::query()->create([
            'slug' => 'custom_'.Role::query()->count(),
            'name' => $name,
            'is_system' => false,
        ]);

        $role->syncPermissions($permissions);

        return User::factory()->roles($role->slug)->create([
            'location_id' => Location::factory()->create()->id,
        ]);
    }

    // The gate itself.

    public function test_a_role_without_the_permission_is_turned_away(): void
    {
        $this->actingAs($this->staffWith())
            ->get('/admin/staff')
            ->assertForbidden();
    }

    public function test_granting_the_permission_opens_the_page(): void
    {
        $this->actingAs($this->staffWith([Permission::ManageStaff]))
            ->get('/admin/staff')
            ->assertOk();
    }

    public function test_a_permission_opens_only_its_own_page(): void
    {
        $user = $this->staffWith([Permission::ManageStaff]);

        $this->actingAs($user)->get('/admin/staff')->assertOk();
        $this->actingAs($user)->get('/admin/locations')->assertForbidden();
        $this->actingAs($user)->get('/admin/roles')->assertForbidden();
    }

    public function test_super_admins_hold_everything_without_a_stored_grant(): void
    {
        $admin = $this->admin();

        foreach (Permission::cases() as $permission) {
            $this->assertTrue(
                $admin->hasPermission($permission),
                "A super admin should hold {$permission->value}.",
            );
        }

        $this->assertSame(0, $admin->roles->first()->rolePermissions()->count());
    }

    public function test_writes_are_guarded_as_well_as_reads(): void
    {
        // Reaching the page is one thing; the form request behind it answers
        // to the same permission.
        $this->actingAs($this->staffWith([Permission::ManageStaff]))
            ->post('/admin/locations', ['name' => 'Back door'])
            ->assertForbidden();
    }

    // What the browser is told.

    public function test_the_shared_props_carry_the_permissions_held(): void
    {
        $this->actingAs($this->staffWith([Permission::ManageStaff, Permission::ViewAuditTrail]))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.permissions', ['staff.manage', 'audit.view'])
                ->etc());
    }

    // Managing the roles themselves.

    public function test_the_roles_page_is_behind_its_own_permission(): void
    {
        $this->actingAs($this->staffWith())->get('/admin/roles')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/roles')->assertOk();
    }

    public function test_a_role_can_be_created_with_permissions(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/roles', [
                'name' => 'Site supervisor',
                'description' => 'Runs a site day to day.',
                'permissions' => ['attendance.report', 'clock-attempts.view'],
            ])
            ->assertSessionHasNoErrors();

        $role = Role::query()->where('name', 'Site supervisor')->firstOrFail();

        $this->assertSame('site_supervisor', $role->slug);
        $this->assertFalse($role->is_system);
        $this->assertTrue($role->hasPermission(Permission::ViewAttendanceReport));
        $this->assertFalse($role->hasPermission(Permission::ManageStaff));
    }

    public function test_a_permission_that_nothing_checks_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/roles', [
                'name' => 'Ghost',
                'permissions' => ['everything.always'],
            ])
            ->assertSessionHasErrors('permissions.0');
    }

    public function test_editing_a_role_replaces_what_it_may_do(): void
    {
        $role = Role::query()->create(['slug' => 'ops', 'name' => 'Ops', 'is_system' => false]);
        $role->syncPermissions([Permission::ManageStaff]);

        $this->actingAs($this->admin())
            ->put("/admin/roles/{$role->id}", [
                'name' => 'Ops',
                'permissions' => ['locations.manage'],
            ])
            ->assertSessionHasNoErrors();

        $role->refresh()->unsetRelation('rolePermissions');

        $this->assertFalse($role->hasPermission(Permission::ManageStaff));
        $this->assertTrue($role->hasPermission(Permission::ManageLocations));
    }

    public function test_a_role_can_be_emptied(): void
    {
        $role = Role::query()->create(['slug' => 'ops', 'name' => 'Ops', 'is_system' => false]);
        $role->syncPermissions([Permission::ManageStaff]);

        $this->actingAs($this->admin())
            ->put("/admin/roles/{$role->id}", ['name' => 'Ops', 'permissions' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $role->refresh()->unsetRelation('rolePermissions')->permissions());
    }

    public function test_super_admins_cannot_be_narrowed(): void
    {
        $role = Role::findBySlug(Role::SUPER_ADMIN);

        $this->actingAs($this->admin())
            ->put("/admin/roles/{$role->id}", [
                'name' => 'Super Admin',
                'permissions' => ['staff.manage'],
            ])
            ->assertSessionHasNoErrors();

        // Still everything, and still nothing stored that could be removed.
        $this->assertCount(count(Permission::cases()), $role->refresh()->permissions());
        $this->assertSame(0, $role->rolePermissions()->count());
    }

    public function test_a_system_role_cannot_be_deleted(): void
    {
        $role = Role::findBySlug(Role::APPROVER);

        $this->actingAs($this->admin())
            ->delete("/admin/roles/{$role->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_a_role_somebody_still_holds_cannot_be_deleted(): void
    {
        $holder = $this->staffWith([], 'Interim');
        $role = $holder->roles()->where('slug', '!=', Role::STAFF)->sole();

        $this->actingAs($this->admin())
            ->delete("/admin/roles/{$role->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_an_unheld_custom_role_can_be_deleted(): void
    {
        $role = Role::query()->create(['slug' => 'temp', 'name' => 'Temp', 'is_system' => false]);
        $role->syncPermissions([Permission::ManageStaff]);

        $this->actingAs($this->admin())
            ->delete("/admin/roles/{$role->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('roles', ['id' => $role->id]);
        $this->assertSoftDeleted('role_permissions', ['role_id' => $role->id]);
    }

    // Holding more than one role.

    public function test_permissions_are_the_union_of_every_role_held(): void
    {
        $reports = Role::query()->create(['slug' => 'reports_desk', 'name' => 'Reports desk', 'is_system' => false]);
        $reports->syncPermissions([Permission::HandleReports]);

        $sites = Role::query()->create(['slug' => 'site_manager', 'name' => 'Site manager', 'is_system' => false]);
        $sites->syncPermissions([Permission::ManageLocations]);

        $person = User::factory()
            ->roles($reports->slug, $sites->slug)
            ->create(['location_id' => Location::factory()->create()->id]);

        $this->assertTrue($person->hasPermission(Permission::HandleReports));
        $this->assertTrue($person->hasPermission(Permission::ManageLocations));
        $this->assertFalse($person->hasPermission(Permission::ManagePayroll));

        $this->actingAs($person)->get('/admin/reports')->assertOk();
        $this->actingAs($person)->get('/admin/locations')->assertOk();
        $this->actingAs($person)->get('/admin/payroll')->assertForbidden();
    }

    public function test_taking_one_role_away_leaves_what_another_role_still_grants(): void
    {
        $first = Role::query()->create(['slug' => 'first_desk', 'name' => 'First', 'is_system' => false]);
        $first->syncPermissions([Permission::ManageStaff]);

        $second = Role::query()->create(['slug' => 'second_desk', 'name' => 'Second', 'is_system' => false]);
        $second->syncPermissions([Permission::ManageStaff, Permission::ManageLocations]);

        $person = User::factory()
            ->roles($first->slug, $second->slug)
            ->create(['location_id' => Location::factory()->create()->id]);

        $person->roles()->detach($second->id);
        $person->unsetRelation('roles');

        // Staff management came from both roles, so losing one keeps it.
        $this->assertTrue($person->hasPermission(Permission::ManageStaff));
        // Sites came from the role that has gone.
        $this->assertFalse($person->hasPermission(Permission::ManageLocations));
    }

    public function test_the_staff_form_will_not_hand_out_a_role_that_comes_with_people(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/staff', [
                'name' => 'Ada Eze',
                'email' => 'ada@example.com',
                'roles' => [Role::STAFF, Role::TEAM_LEAD],
                'location_id' => Location::factory()->create()->id,
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertSessionHasErrors('roles.1');

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    // The approvals inbox, which is not a permission alone.

    public function test_approving_follows_the_roles_page_rather_than_a_fixed_role(): void
    {
        $granted = $this->staffWith([Permission::ApproveRequests]);

        $this->assertTrue($granted->canApprove());
        $this->assertFalse($this->staffWith()->canApprove());
    }

    public function test_the_shipped_approver_role_keeps_its_inbox(): void
    {
        $approver = User::factory()->approver()->create([
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->assertTrue($approver->canApprove());
        $this->actingAs($approver)->get('/approvals')->assertOk();
    }
}
