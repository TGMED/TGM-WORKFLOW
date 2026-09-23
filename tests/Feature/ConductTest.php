<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Enums\Permission;
use App\Enums\StaffActionKind;
use App\Models\Department;
use App\Models\EmploymentSettings;
use App\Models\Location;
use App\Models\NotificationSetting;
use App\Models\Role;
use App\Models\StaffAction;
use App\Models\User;
use App\Notifications\QueryAnswered;
use App\Notifications\StaffActionIssued;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Queries, warnings and confirmations, and how long probation runs.
 */
class ConductTest extends TestCase
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
        return User::factory()->create(['location_id' => $this->location->id, ...$attributes]);
    }

    private function hr(): User
    {
        $role = Role::query()->create(['slug' => 'people_team', 'name' => 'People team', 'is_system' => false]);
        $role->syncPermissions([Permission::ManageStaff, Permission::IssueConduct]);

        return User::factory()->roles($role->slug)->create(['location_id' => $this->location->id]);
    }

    public function test_a_query_is_emailed_to_the_person_their_head_and_hr(): void
    {
        Notification::fake();

        $hr = $this->hr();
        $department = Department::factory()->create();
        $head = $this->staff(['department_id' => $department->id]);
        $department->update(['head_user_id' => $head->id]);
        $member = $this->staff(['department_id' => $department->id]);
        $bystander = $this->staff();

        $this->actingAs($hr)
            ->post('/admin/conduct', [
                'subject_user_id' => $member->id,
                'kind' => 'query',
                'title' => 'Absent without leave on 12 September',
                'body' => 'You were not at work and did not call in. Explain in writing.',
                'response_due_on' => Carbon::today()->addDays(3)->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $action = StaffAction::query()->sole();
        $this->assertSame(StaffActionKind::Query, $action->kind);
        $this->assertSame($hr->id, $action->issued_by_id);

        Notification::assertSentTo([$member, $head, $hr], StaffActionIssued::class);
        Notification::assertNotSentTo($bystander, StaffActionIssued::class);
    }

    public function test_the_email_cannot_be_switched_off(): void
    {
        $member = $this->staff();

        NotificationSetting::query()->create([
            'user_id' => $member->id,
            'topic' => NotificationTopic::StaffAction,
            'email' => false,
            'push' => false,
        ]);

        $this->assertTrue(NotificationSetting::allows($member, NotificationTopic::StaffAction, NotificationChannel::Email));
    }

    public function test_a_query_needs_a_due_date(): void
    {
        $this->actingAs($this->hr())
            ->post('/admin/conduct', [
                'subject_user_id' => $this->staff()->id,
                'kind' => 'query',
                'title' => 'Late again',
                'body' => 'Explain the lateness this week.',
            ])
            ->assertSessionHasErrors('response_due_on');
    }

    public function test_confirming_somebody_changes_their_record(): void
    {
        Notification::fake();

        $member = $this->staff(['employment_status' => EmploymentStatus::Probation, 'confirmed_at' => null]);

        $this->actingAs($this->hr())
            ->post('/admin/conduct', [
                'subject_user_id' => $member->id,
                'kind' => 'confirmation',
                'body' => 'Well done on completing your probation.',
            ])
            ->assertSessionHasNoErrors();

        $member->refresh();
        $this->assertSame(EmploymentStatus::Confirmed, $member->employment_status);
        $this->assertTrue($member->confirmed_at->isToday());
        $this->assertSame('Confirmation of appointment', StaffAction::query()->sole()->title);
    }

    public function test_somebody_already_confirmed_cannot_be_confirmed_again(): void
    {
        $member = $this->staff(['employment_status' => EmploymentStatus::Confirmed]);

        $this->actingAs($this->hr())
            ->post('/admin/conduct', [
                'subject_user_id' => $member->id,
                'kind' => 'confirmation',
                'body' => 'Well done on completing your probation.',
            ])
            ->assertSessionHasErrors('subject_user_id');
    }

    public function test_issuing_needs_its_own_permission(): void
    {
        $this->actingAs($this->staff())->get('/admin/conduct')->assertForbidden();
    }

    public function test_the_person_answers_a_query_and_hr_is_told(): void
    {
        Notification::fake();

        $hr = $this->hr();
        $member = $this->staff();
        $action = StaffAction::factory()->query()->create(['subject_user_id' => $member->id, 'issued_by_id' => $hr->id]);

        $this->actingAs($member)
            ->post("/conduct/{$action->id}/respond", ['response' => 'My car broke down on the expressway.'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($action->refresh()->responded_at);
        Notification::assertSentTo($hr, QueryAnswered::class);
        Notification::assertNotSentTo($member, QueryAnswered::class);
    }

    public function test_nobody_answers_somebody_else_s_query(): void
    {
        $action = StaffAction::factory()->query()->create();

        $this->actingAs($this->staff())
            ->post("/conduct/{$action->id}/respond", ['response' => 'Not mine to answer at all.'])
            ->assertForbidden();
    }

    public function test_a_warning_is_acknowledged_not_answered(): void
    {
        $member = $this->staff();
        $action = StaffAction::factory()->create(['subject_user_id' => $member->id]);

        $this->actingAs($member)->post("/conduct/{$action->id}/acknowledge")->assertRedirect();

        $this->assertNotNull($action->refresh()->acknowledged_at);
    }

    public function test_probation_follows_the_company_unless_set_on_the_person(): void
    {
        EmploymentSettings::current()->update(['probation_months' => 3]);

        $company = $this->staff(['hired_at' => '2026-01-10']);
        $own = $this->staff(['hired_at' => '2026-01-10', 'probation_months' => 9]);

        $this->assertSame('2026-04-10', $company->confirmationDueOn()->toDateString());
        $this->assertSame('2026-10-10', $own->confirmationDueOn()->toDateString());
    }

    public function test_hr_sets_the_company_probation_length(): void
    {
        $this->actingAs($this->hr())
            ->put('/admin/conduct/probation', ['probation_months' => 4])
            ->assertSessionHasNoErrors();

        $this->assertSame(4, EmploymentSettings::probationMonths());
    }

    public function test_a_probation_length_can_be_given_when_somebody_is_added(): void
    {
        $role = Role::query()->create(['slug' => 'staff_admin', 'name' => 'Staff admin', 'is_system' => false]);
        $role->syncPermissions([Permission::ManageStaff]);
        $admin = User::factory()->roles($role->slug)->create(['location_id' => $this->location->id]);

        Notification::fake();

        $this->actingAs($admin)
            ->post('/admin/staff', [
                'name' => 'Ngozi Eze',
                'email' => 'ngozi@example.com',
                'roles' => ['staff'],
                'location_id' => $this->location->id,
                'probation_months' => 3,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, User::query()->where('email', 'ngozi@example.com')->sole()->probation_months);
    }

    public function test_both_pages_list_the_letters(): void
    {
        $hr = $this->hr();
        $member = $this->staff();
        StaffAction::factory()->query()->create(['subject_user_id' => $member->id, 'issued_by_id' => $hr->id]);

        $this->actingAs($member)
            ->get('/conduct')
            ->assertInertia(fn ($page) => $page->component('Conduct')->has('actions', 1)->where('actions.0.expects_response', true));

        $this->actingAs($hr)
            ->get('/admin/conduct?kind=query')
            ->assertInertia(fn ($page) => $page->component('admin/Conduct')->has('actions.data', 1)->where('counts.query', 1));
    }
}
