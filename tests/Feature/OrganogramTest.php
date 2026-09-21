<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Department;
use App\Models\Location;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who reports to whom, drawn from the reporting line.
 */
class OrganogramTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create([
            'location_id' => $this->location->id,
            ...$attributes,
        ]);
    }

    /**
     * Somebody from the people team, who may rearrange the chart rather than
     * only read it.
     */
    private function organiser(): User
    {
        $role = Role::query()->create([
            'slug' => 'people_'.Role::query()->count(),
            'name' => 'People team',
            'is_system' => false,
        ]);

        $role->syncPermissions([Permission::ManageDepartments]);

        return User::factory()->roles($role->slug)->create([
            'location_id' => $this->location->id,
        ]);
    }

    public function test_everybody_can_read_the_chart(): void
    {
        $head = $this->staff();
        $manager = $this->staff(['manager_id' => $head->id]);
        $junior = $this->staff(['manager_id' => $manager->id]);

        $this->actingAs($junior)
            ->get('/organogram')
            ->assertOk()
            ->assertInertia(function ($page) use ($head, $manager, $junior) {
                $props = $page->toArray()['props'];

                $this->assertSame(3, $props['totals']['people']);
                $this->assertSame([$head->id], $props['roots']);

                $nodes = collect($props['nodes'])->keyBy('id');

                $this->assertSame($manager->id, $nodes[$junior->id]['manager_id']);
                $this->assertSame(1, $nodes[$head->id]['reports']);
                // Everyone below, however many rungs down.
                $this->assertSame(2, $nodes[$head->id]['below']);
                $this->assertTrue($nodes[$junior->id]['is_you']);
            });
    }

    public function test_somebody_pointing_at_a_departed_manager_sits_at_the_top(): void
    {
        $manager = $this->staff();
        $orphan = $this->staff(['manager_id' => $manager->id]);

        // Deactivated rather than deleted, which is what an exit does.
        $manager->update(['is_active' => false]);

        $this->actingAs($orphan)
            ->get('/organogram')
            ->assertInertia(fn ($page) => $page->where('roots', [$orphan->id]));
    }

    public function test_a_loop_in_the_data_does_not_hang_the_page(): void
    {
        $one = $this->staff();
        $two = $this->staff(['manager_id' => $one->id]);
        $one->forceFill(['manager_id' => $two->id])->save();

        $this->actingAs($this->staff())
            ->get('/organogram')
            ->assertOk();
    }

    // Rearranging it.

    public function test_the_people_team_puts_somebody_under_a_manager(): void
    {
        $manager = $this->staff();
        $person = $this->staff();

        $this->actingAs($this->organiser())
            ->put("/admin/organogram/{$person->id}/manager", ['manager_id' => $manager->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($manager->id, $person->refresh()->manager_id);
    }

    public function test_somebody_can_be_lifted_to_the_top_of_the_chart(): void
    {
        $person = $this->staff(['manager_id' => $this->staff()->id]);

        $this->actingAs($this->organiser())
            ->put("/admin/organogram/{$person->id}/manager", ['manager_id' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull($person->refresh()->manager_id);
    }

    public function test_a_loop_is_refused_rather_than_written(): void
    {
        $top = $this->staff();
        $middle = $this->staff(['manager_id' => $top->id]);
        $bottom = $this->staff(['manager_id' => $middle->id]);

        // Pointing the top at somebody already below it would leave the three
        // of them reporting only to each other.
        $this->actingAs($this->organiser())
            ->put("/admin/organogram/{$top->id}/manager", ['manager_id' => $bottom->id])
            ->assertSessionHasErrors('manager_id');

        $this->assertNull($top->refresh()->manager_id);
    }

    public function test_nobody_is_made_to_report_to_themselves(): void
    {
        $person = $this->staff();

        $this->actingAs($this->organiser())
            ->put("/admin/organogram/{$person->id}/manager", ['manager_id' => $person->id])
            ->assertSessionHasErrors('manager_id');

        $this->assertNull($person->refresh()->manager_id);
    }

    public function test_ordinary_staff_cannot_rearrange_the_chart(): void
    {
        $person = $this->staff();
        $manager = $this->staff();

        $this->actingAs($this->staff())
            ->put("/admin/organogram/{$person->id}/manager", ['manager_id' => $manager->id])
            ->assertForbidden();

        $this->assertNull($person->refresh()->manager_id);
    }

    public function test_naming_a_head_from_the_chart_grants_the_role(): void
    {
        $department = Department::factory()->create();
        $head = $this->staff();

        $this->actingAs($this->organiser())
            ->put("/admin/organogram/departments/{$department->id}/head", [
                'head_user_id' => $head->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($head->id, $department->refresh()->head_user_id);
        // Naming somebody grants the role that carries the approval rights;
        // that is the whole reason this does not write the column directly.
        $this->assertTrue($head->refresh()->hasRole(Role::HEAD_OF_DEPARTMENT));
    }

    public function test_naming_a_team_lead_from_the_chart_grants_the_role(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->create(['department_id' => $department->id]);
        $lead = $this->staff();

        $this->actingAs($this->organiser())
            ->put("/admin/organogram/teams/{$team->id}/lead", ['lead_user_id' => $lead->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($lead->id, $team->refresh()->lead_user_id);
        $this->assertTrue($lead->refresh()->hasRole(Role::TEAM_LEAD));
    }

    public function test_the_chart_only_offers_the_pickers_to_somebody_who_may_use_them(): void
    {
        Department::factory()->create();

        $this->actingAs($this->staff())
            ->get('/organogram')
            ->assertInertia(fn ($page) => $page
                ->where('can_manage', false)
                ->where('units', [])
                ->where('assignable', []));

        $this->actingAs($this->organiser())
            ->get('/organogram')
            ->assertInertia(fn ($page) => $page
                ->where('can_manage', true)
                ->has('units', 1)
                ->has('assignable'));
    }

    public function test_people_with_no_manager_are_counted_in_plain_sight(): void
    {
        $this->staff();
        $this->staff();

        $this->actingAs($this->staff())
            ->get('/organogram')
            ->assertInertia(fn ($page) => $page->where('totals.unplaced', 3));
    }
}
