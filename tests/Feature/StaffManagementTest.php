<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create(['name' => 'TGM Head Office']);
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create([
            'location_id' => $this->location->id,
        ]);
    }

    public function test_staff_cannot_reach_the_admin_area(): void
    {
        $staff = User::factory()->create(['location_id' => $this->location->id]);

        $this->actingAs($staff)->get('/admin/staff')->assertForbidden();
        $this->actingAs($staff)->get('/admin/locations')->assertForbidden();
        $this->actingAs($staff)->get('/admin/clock-attempts')->assertForbidden();
    }

    public function test_super_admins_see_the_staff_list(): void
    {
        User::factory(3)->create(['location_id' => $this->location->id]);

        $this->actingAs($this->admin())->get('/admin/staff')->assertOk();
    }

    public function test_super_admins_can_add_staff(): void
    {
        $this->actingAs($this->admin())->post('/admin/staff', [
            'name' => 'Amara Nwosu',
            'email' => 'amara@tgm.test',
            'employee_id' => 'TGM-0099',
            'role' => Role::STAFF,
            'department' => 'Engineering',
            'location_id' => $this->location->id,
            'password' => 'Correct-Horse-Battery-9',
            'password_confirmation' => 'Correct-Horse-Battery-9',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'amara@tgm.test',
            'employee_id' => 'TGM-0099',
            'location_id' => $this->location->id,
            'is_active' => true,
        ]);
    }

    public function test_adding_staff_requires_a_work_location(): void
    {
        $this->actingAs($this->admin())->post('/admin/staff', [
            'name' => 'No Site',
            'email' => 'nosite@tgm.test',
            'role' => Role::STAFF,
            'password' => 'Correct-Horse-Battery-9',
            'password_confirmation' => 'Correct-Horse-Battery-9',
        ])->assertSessionHasErrors('location_id');

        $this->assertDatabaseMissing('users', ['email' => 'nosite@tgm.test']);
    }

    public function test_staff_cannot_add_staff(): void
    {
        $this->actingAs(User::factory()->create(['location_id' => $this->location->id]))
            ->post('/admin/staff', [
                'name' => 'Sneaky',
                'email' => 'sneaky@tgm.test',
                'role' => Role::SUPER_ADMIN,
                'location_id' => $this->location->id,
                'password' => 'Correct-Horse-Battery-9',
                'password_confirmation' => 'Correct-Horse-Battery-9',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@tgm.test']);
    }

    public function test_super_admins_can_move_a_staff_member_to_another_location(): void
    {
        $other = Location::factory()->create(['name' => 'TGM Abuja']);
        $staff = User::factory()->create(['location_id' => $this->location->id]);

        $this->actingAs($this->admin())
            ->put("/admin/staff/{$staff->id}", [
                'name' => $staff->name,
                'email' => $staff->email,
                'role' => Role::STAFF,
                'location_id' => $other->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($other->id, $staff->refresh()->location_id);
    }

    public function test_editing_without_a_password_keeps_the_existing_one(): void
    {
        $staff = User::factory()->create([
            'password' => 'password',
            'location_id' => $this->location->id,
        ]);
        $hash = $staff->password;

        $this->actingAs($this->admin())->put("/admin/staff/{$staff->id}", [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => Role::STAFF,
            'location_id' => $this->location->id,
            'password' => '',
        ]);

        $this->assertSame($hash, $staff->refresh()->password);
    }

    public function test_super_admins_can_deactivate_and_reactivate_staff(): void
    {
        $staff = User::factory()->create(['location_id' => $this->location->id]);

        $this->actingAs($this->admin())
            ->patch("/admin/staff/{$staff->id}/toggle")
            ->assertSessionHasNoErrors();

        $staff->refresh();
        $this->assertFalse($staff->is_active);
        $this->assertNotNull($staff->deactivated_at);

        $this->actingAs($this->admin())->patch("/admin/staff/{$staff->id}/toggle");

        $staff->refresh();
        $this->assertTrue($staff->is_active);
        $this->assertNull($staff->deactivated_at);
    }

    public function test_admins_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patch("/admin/staff/{$admin->id}/toggle");

        $this->assertTrue($admin->refresh()->is_active);
    }

    public function test_the_staff_list_can_be_filtered_by_location(): void
    {
        $other = Location::factory()->create();

        User::factory(2)->create(['location_id' => $this->location->id]);
        User::factory(3)->create(['location_id' => $other->id]);
        User::factory()->create(['location_id' => null]);

        $this->actingAs($this->admin())
            ->get("/admin/staff?location={$other->id}")
            ->assertOk();

        $this->actingAs($this->admin())
            ->get('/admin/staff?location=none')
            ->assertOk();
    }
}
