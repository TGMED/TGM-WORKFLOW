<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Location;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AddedToDepartment;
use App\Notifications\NamedHeadOfDepartment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Departments and the teams inside them.
 *
 * The rule these tests exist for: a head of department or a team lead is never
 * granted on its own. The role and the people it covers are settled together,
 * or not at all.
 */
class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function staff(int $count = 1): User
    {
        return User::factory()->create(['location_id' => $this->location->id]);
    }

    // Who hears about a move.

    public function test_the_head_and_the_new_members_are_written_to(): void
    {
        Notification::fake();

        $head = $this->staff();
        $member = $this->staff();

        $this->actingAs($this->admin())
            ->post('/admin/departments', [
                'name' => 'Operations',
                'head_user_id' => $head->id,
                'members' => [$head->id, $member->id],
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($head, NamedHeadOfDepartment::class);
        Notification::assertSentTo($member, AddedToDepartment::class);

        // Being handed the department says everything joining it would have,
        // so the head does not get both.
        Notification::assertNotSentTo($head, AddedToDepartment::class);
    }

    public function test_people_already_in_the_department_are_left_alone(): void
    {
        $head = $this->staff();
        $sitting = $this->staff();

        $this->actingAs($this->admin())->post('/admin/departments', [
            'name' => 'Operations',
            'head_user_id' => $head->id,
            'members' => [$head->id, $sitting->id],
        ]);

        $department = Department::query()->where('name', 'Operations')->sole();
        $arriving = $this->staff();

        Notification::fake();

        // One person added; the rest of the form comes back unchanged.
        $this->actingAs($this->admin())
            ->put("/admin/departments/{$department->id}", [
                'name' => 'Operations',
                'head_user_id' => $head->id,
                'members' => [$head->id, $sitting->id, $arriving->id],
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($arriving, AddedToDepartment::class);
        Notification::assertNotSentTo($sitting, AddedToDepartment::class);
        // The head has not changed, so there is nothing to tell them either.
        Notification::assertNotSentTo($head, NamedHeadOfDepartment::class);
    }

    public function test_a_new_head_of_a_standing_department_is_told(): void
    {
        $first = $this->staff();
        $member = $this->staff();

        $this->actingAs($this->admin())->post('/admin/departments', [
            'name' => 'Operations',
            'head_user_id' => $first->id,
            'members' => [$first->id, $member->id],
        ]);

        $department = Department::query()->where('name', 'Operations')->sole();

        Notification::fake();

        $this->actingAs($this->admin())
            ->put("/admin/departments/{$department->id}", [
                'name' => 'Operations',
                'head_user_id' => $member->id,
                'members' => [$first->id, $member->id],
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($member, NamedHeadOfDepartment::class);
        Notification::assertNothingSentTo($first);
    }

    public function test_nobody_is_written_to_when_a_department_is_dissolved(): void
    {
        $head = $this->staff();
        $member = $this->staff();

        $this->actingAs($this->admin())->post('/admin/departments', [
            'name' => 'Operations',
            'head_user_id' => $head->id,
            'members' => [$head->id, $member->id],
        ]);

        $department = Department::query()->where('name', 'Operations')->sole();

        Notification::fake();

        $this->actingAs($this->admin())
            ->delete("/admin/departments/{$department->id}")
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_the_department_mails_render(): void
    {
        $head = $this->staff();
        $member = $this->staff();

        $this->actingAs($this->admin())->post('/admin/departments', [
            'name' => 'Operations',
            'head_user_id' => $head->id,
            'members' => [$head->id, $member->id],
        ]);

        $department = Department::query()->where('name', 'Operations')->sole()->load('head');

        $headMail = (new NamedHeadOfDepartment($department))->toMail($head);
        $this->assertStringContainsString('Operations', (string) $headMail->subject);

        $memberMail = (new AddedToDepartment($department))->toMail($member);
        $this->assertStringContainsString(
            $head->name,
            (string) $memberMail->viewData['panel'],
        );
    }

    // Creating a department.

    public function test_a_department_can_be_created_with_a_head_and_its_people(): void
    {
        $head = $this->staff();
        $member = $this->staff();

        $this->actingAs($this->admin())
            ->post('/admin/departments', [
                'name' => 'Operations',
                'head_user_id' => $head->id,
                'members' => [$head->id, $member->id],
            ])
            ->assertSessionHasNoErrors();

        $department = Department::query()->where('name', 'Operations')->sole();

        $this->assertSame($head->id, $department->head_user_id);
        $this->assertEqualsCanonicalizing(
            [$head->id, $member->id],
            $department->members()->pluck('id')->all(),
        );
    }

    public function test_naming_a_head_grants_them_the_role(): void
    {
        $head = $this->staff();
        $member = $this->staff();

        $this->actingAs($this->admin())->post('/admin/departments', [
            'name' => 'Operations',
            'head_user_id' => $head->id,
            'members' => [$head->id, $member->id],
        ]);

        $this->assertTrue($head->fresh()->hasRole(Role::HEAD_OF_DEPARTMENT));
        // The role they already held is not taken away by the new one.
        $this->assertTrue($head->fresh()->hasRole(Role::STAFF));
    }

    public function test_a_head_cannot_be_named_without_anybody_to_head(): void
    {
        $head = $this->staff();

        // Themselves alone does not count: a head of nobody is the empty role
        // grant this page exists to prevent.
        $this->actingAs($this->admin())
            ->post('/admin/departments', [
                'name' => 'Operations',
                'head_user_id' => $head->id,
                'members' => [$head->id],
            ])
            ->assertSessionHasErrors('members');

        $this->assertDatabaseMissing('departments', ['name' => 'Operations']);
        $this->assertFalse($head->fresh()->hasRole(Role::HEAD_OF_DEPARTMENT));
    }

    public function test_a_department_may_be_created_without_a_head(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/departments', ['name' => 'Operations'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('departments', ['name' => 'Operations', 'head_user_id' => null]);
    }

    public function test_dropping_a_head_takes_the_role_back(): void
    {
        $head = $this->staff();
        $member = $this->staff();

        $this->actingAs($this->admin())->post('/admin/departments', [
            'name' => 'Operations',
            'head_user_id' => $head->id,
            'members' => [$head->id, $member->id],
        ]);

        $department = Department::query()->sole();

        $this->actingAs($this->admin())->put("/admin/departments/{$department->id}", [
            'name' => 'Operations',
            'head_user_id' => null,
            'members' => [$head->id, $member->id],
        ]);

        $this->assertFalse($head->fresh()->hasRole(Role::HEAD_OF_DEPARTMENT));
        // Never left with nothing: they go back to being staff.
        $this->assertTrue($head->fresh()->hasRole(Role::STAFF));
    }

    public function test_somebody_heading_two_departments_keeps_the_role_when_one_goes(): void
    {
        $head = $this->staff();

        foreach (['Operations', 'Logistics'] as $name) {
            $this->actingAs($this->admin())->post('/admin/departments', [
                'name' => $name,
                'head_user_id' => $head->id,
                'members' => [$head->id, $this->staff()->id],
            ]);
        }

        $first = Department::query()->where('name', 'Operations')->sole();

        $this->actingAs($this->admin())->delete("/admin/departments/{$first->id}");

        $this->assertTrue($head->fresh()->hasRole(Role::HEAD_OF_DEPARTMENT));
    }

    // Teams.

    public function test_a_team_lead_must_come_with_a_team(): void
    {
        $department = Department::factory()->create();
        $lead = User::factory()->inDepartment($department)->create(['location_id' => $this->location->id]);

        // A lead of themselves alone is not a team.
        $this->actingAs($this->admin())
            ->post("/admin/departments/{$department->id}/teams", [
                'name' => 'Night shift',
                'lead_user_id' => $lead->id,
                'members' => [$lead->id],
            ])
            ->assertSessionHasErrors('members');

        $this->assertDatabaseMissing('teams', ['name' => 'Night shift']);
        $this->assertFalse($lead->fresh()->hasRole(Role::TEAM_LEAD));
    }

    public function test_naming_a_lead_grants_the_role_and_puts_them_on_the_team(): void
    {
        $department = Department::factory()->create();
        $lead = User::factory()->inDepartment($department)->create(['location_id' => $this->location->id]);
        $member = User::factory()->inDepartment($department)->create(['location_id' => $this->location->id]);

        $this->actingAs($this->admin())
            ->post("/admin/departments/{$department->id}/teams", [
                'name' => 'Night shift',
                'lead_user_id' => $lead->id,
                'members' => [$lead->id, $member->id],
            ])
            ->assertSessionHasNoErrors();

        $team = Team::query()->sole();

        $this->assertSame($lead->id, $team->lead_user_id);
        $this->assertTrue($lead->fresh()->hasRole(Role::TEAM_LEAD));
        $this->assertSame($team->id, $lead->fresh()->team_id);
        $this->assertSame($team->id, $member->fresh()->team_id);
    }

    public function test_a_team_cannot_be_drawn_from_outside_its_department(): void
    {
        $department = Department::factory()->create();
        $outsider = $this->staff();

        $this->actingAs($this->admin())
            ->post("/admin/departments/{$department->id}/teams", [
                'name' => 'Night shift',
                'members' => [$outsider->id],
            ])
            ->assertSessionHasErrors('members.0');
    }

    public function test_disbanding_a_team_leaves_its_people_in_the_department(): void
    {
        $department = Department::factory()->create();
        $lead = User::factory()->inDepartment($department)->create(['location_id' => $this->location->id]);

        $member = User::factory()->inDepartment($department)->create(['location_id' => $this->location->id]);

        $this->actingAs($this->admin())->post("/admin/departments/{$department->id}/teams", [
            'name' => 'Night shift',
            'lead_user_id' => $lead->id,
            'members' => [$lead->id, $member->id],
        ]);

        $team = Team::query()->sole();

        $this->actingAs($this->admin())->delete("/admin/teams/{$team->id}");

        $lead->refresh();

        $this->assertNull($lead->team_id);
        $this->assertSame($department->id, $lead->department_id);
        $this->assertFalse($lead->hasRole(Role::TEAM_LEAD));
    }

    // Moving people about.

    public function test_moving_somebody_to_another_department_drops_the_team_they_left_behind(): void
    {
        $first = Department::factory()->create();
        $second = Department::factory()->create();

        $person = User::factory()->inDepartment($first)->create(['location_id' => $this->location->id]);

        $this->actingAs($this->admin())->post("/admin/departments/{$first->id}/teams", [
            'name' => 'Night shift',
            'members' => [$person->id],
        ]);

        $this->assertNotNull($person->fresh()->team_id);

        $this->actingAs($this->admin())->put("/admin/departments/{$second->id}", [
            'name' => $second->name,
            'members' => [$person->id],
        ]);

        $person->refresh();

        $this->assertSame($second->id, $person->department_id);
        $this->assertNull($person->team_id);
    }

    public function test_removing_a_department_leaves_its_people_in_none(): void
    {
        $department = Department::factory()->create();
        $person = User::factory()->inDepartment($department)->create(['location_id' => $this->location->id]);

        $this->actingAs($this->admin())->delete("/admin/departments/{$department->id}");

        $this->assertNull($person->fresh()->department_id);
        $this->assertSoftDeleted('departments', ['id' => $department->id]);
    }

    public function test_renaming_a_department_does_not_turn_everybody_out_of_it(): void
    {
        $department = Department::factory()->create(['name' => 'Ops']);
        $person = User::factory()->inDepartment($department)->create(['location_id' => $this->location->id]);

        // No members key at all: this request is about the name, nothing else.
        $this->actingAs($this->admin())
            ->put("/admin/departments/{$department->id}", ['name' => 'Operations'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Operations', $department->fresh()->name);
        $this->assertSame($department->id, $person->fresh()->department_id);
    }

    public function test_naming_nobody_does_empty_a_department(): void
    {
        $department = Department::factory()->create();
        $person = User::factory()->inDepartment($department)->create(['location_id' => $this->location->id]);

        // Sent, and naming nobody. That is a real instruction, unlike the
        // absent key above.
        $this->actingAs($this->admin())
            ->put("/admin/departments/{$department->id}", [
                'name' => $department->name,
                'members' => [],
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($person->fresh()->department_id);
    }

    // The gate.

    public function test_the_page_is_behind_its_own_permission(): void
    {
        $this->actingAs($this->staff())->get('/admin/departments')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/departments')->assertOk();
    }
}
