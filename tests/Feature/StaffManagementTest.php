<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Enums\ExitReason;
use App\Models\Department;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
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
            'roles' => [Role::STAFF],
            'department_id' => Department::factory()->create()->id,
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
            'roles' => [Role::STAFF],
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
                'roles' => [Role::SUPER_ADMIN],
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
                'roles' => [Role::STAFF],
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
            'roles' => [Role::STAFF],
            'location_id' => $this->location->id,
            'password' => '',
        ]);

        $this->assertSame($hash, $staff->refresh()->password);
    }

    /**
     * The edit form leaves the password out of the request entirely when it
     * has not been touched, rather than sending an empty pair, so the route
     * has to take a payload with no password key at all.
     */
    public function test_editing_without_the_password_fields_at_all_keeps_the_existing_one(): void
    {
        $staff = User::factory()->create([
            'password' => 'password',
            'location_id' => $this->location->id,
        ]);
        $hash = $staff->password;

        $this->actingAs($this->admin())
            ->put("/admin/staff/{$staff->id}", [
                'name' => 'Renamed Person',
                'email' => $staff->email,
                'roles' => [Role::STAFF],
                'location_id' => $this->location->id,
            ])
            ->assertSessionHasNoErrors();

        $staff->refresh();

        $this->assertSame('Renamed Person', $staff->name);
        $this->assertSame($hash, $staff->password);
    }

    public function test_the_staff_page_carries_the_whole_record(): void
    {
        $staff = User::factory()->create([
            'location_id' => $this->location->id,
            'employment_status' => EmploymentStatus::Probation,
            'confirmed_at' => null,
        ]);

        $this->actingAs($this->admin())
            ->get("/admin/staff/{$staff->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/StaffShow')
                ->where('staff.employment_status', 'probation')
                ->where('staff.employment_status_label', 'On probation')
                ->where('staff.employment_status_tone', 'brass')
                ->where('staff.confirmed_at', null)
                ->has('staff.email_verified_at')
                ->has('staff.created_at')
                ->has('staff.updated_at')
            );
    }

    /**
     * The HR record the employee keeps themselves is sent whole, filled in or
     * not, so the page can show the gaps as gaps rather than dropping the row.
     */
    public function test_the_staff_page_carries_the_employee_profile(): void
    {
        $staff = User::factory()->create(['location_id' => $this->location->id]);

        // The factory already gave them one; this is the record as filled in.
        $staff->profile->update([
            'first_name' => 'Amara',
            'last_name' => 'Nwosu',
            'bank_name' => 'Zenith Bank',
            'genotype' => null,
            'rsa_number' => null,
        ]);

        $this->actingAs($this->admin())
            ->get("/admin/staff/{$staff->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('profile.exists', true)
                ->where('profile.first_name', 'Amara')
                ->where('profile.bank_name', 'Zenith Bank')
                ->where('profile.genotype', null)
                ->where('profile.rsa_number', null)
                ->has('profile.bvn')
                ->has('profile.national_id_number')
                ->has('profile.tax_identification_number')
                ->etc()
            );
    }

    public function test_the_staff_page_copes_with_no_employee_profile(): void
    {
        // The factory gives everyone a profile, so this is the imported staff
        // member whose HR record has not been started yet.
        $staff = User::factory()->create(['location_id' => $this->location->id]);
        $staff->profile()->forceDelete();

        $this->assertNull($staff->fresh()->profile);

        $this->actingAs($this->admin())
            ->get("/admin/staff/{$staff->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('profile.exists', false)
                ->where('profile.first_name', null)
                ->etc()
            );
    }

    public function test_super_admins_can_walk_staff_out_and_reinstate_them(): void
    {
        $staff = User::factory()->create(['location_id' => $this->location->id]);

        $this->actingAs($this->admin())
            ->post("/admin/staff/{$staff->id}/exit", [
                'exit_reason' => ExitReason::Resignation->value,
                'exit_date' => Carbon::now()->toDateString(),
                'exit_note' => 'Moving to another company.',
            ])
            ->assertSessionHasNoErrors();

        $staff->refresh();
        $this->assertFalse($staff->is_active);
        $this->assertNotNull($staff->deactivated_at);
        $this->assertSame(ExitReason::Resignation, $staff->exit_reason);
        $this->assertSame('Moving to another company.', $staff->exit_note);
        $this->assertTrue($staff->hasExited());

        $this->actingAs($this->admin())->patch("/admin/staff/{$staff->id}/reinstate");

        $staff->refresh();
        $this->assertTrue($staff->is_active);
        $this->assertNull($staff->deactivated_at);
        $this->assertNull($staff->exit_reason);
        $this->assertNull($staff->exit_date);
        $this->assertFalse($staff->hasExited());
    }

    public function test_an_exit_needs_a_reason_and_a_last_day(): void
    {
        $staff = User::factory()->create(['location_id' => $this->location->id]);

        $this->actingAs($this->admin())
            ->post("/admin/staff/{$staff->id}/exit", [])
            ->assertSessionHasErrors(['exit_reason', 'exit_date']);

        $this->assertTrue($staff->refresh()->is_active);
    }

    public function test_admins_cannot_walk_themselves_out(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/staff/{$admin->id}/exit", [
            'exit_reason' => ExitReason::Resignation->value,
            'exit_date' => Carbon::now()->toDateString(),
        ]);

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
