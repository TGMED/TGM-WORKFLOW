<?php

namespace Tests\Feature;

use App\Enums\RequestModule;
use App\Models\ApprovalSetting;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\User;
use App\Notifications\ApprovalOverdue;
use App\Services\ApprovalEscalation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A request nobody decides is forgotten rather than refused. After a module's
 * stretch, the people team and the manager of whoever is sitting on it hear
 * about it.
 */
class ApprovalEscalationTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();

        ApprovalSetting::for(RequestModule::Leave)->update(['escalation_hours' => 48]);
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create([
            'location_id' => $this->location->id,
            ...$attributes,
        ]);
    }

    private function stale(User $requester, int $daysOld = 3): LeaveRequest
    {
        $leave = LeaveRequest::factory()->create(['user_id' => $requester->id]);

        $leave->forceFill(['created_at' => Carbon::now()->subDays($daysOld)])->save();

        return $leave->refresh();
    }

    public function test_the_people_team_hears_about_a_request_left_waiting(): void
    {
        Notification::fake();

        $hr = User::factory()->superAdmin()->create();
        $requester = $this->staff();
        User::factory()->approver()->create(['location_id' => $this->location->id]);

        $leave = $this->stale($requester);

        $chased = app(ApprovalEscalation::class)->run();

        $this->assertSame(1, $chased);
        Notification::assertSentTo($hr, ApprovalOverdue::class);
        $this->assertNotNull($leave->refresh()->escalated_at);
    }

    public function test_the_manager_of_whoever_is_sitting_on_it_hears_too(): void
    {
        Notification::fake();

        User::factory()->superAdmin()->create();
        $director = $this->staff();
        User::factory()->approver()->create([
            'location_id' => $this->location->id,
            'manager_id' => $director->id,
        ]);

        $this->stale($this->staff());

        app(ApprovalEscalation::class)->run();

        Notification::assertSentTo($director, ApprovalOverdue::class);
    }

    public function test_the_approver_sitting_on_it_is_not_chased_through_their_own_manager(): void
    {
        Notification::fake();

        User::factory()->superAdmin()->create();

        // The approver manages themselves out of the manager route by being
        // the manager of the other approver. They are the one person who could
        // act on this, and have already been asked directly.
        $approver = User::factory()->approver()->create(['location_id' => $this->location->id]);
        User::factory()->approver()->create([
            'location_id' => $this->location->id,
            'manager_id' => $approver->id,
        ]);

        $this->stale($this->staff());

        app(ApprovalEscalation::class)->run();

        Notification::assertNotSentTo($approver, ApprovalOverdue::class);
    }

    public function test_a_request_is_chased_once_and_not_every_hour(): void
    {
        Notification::fake();

        User::factory()->superAdmin()->create();
        User::factory()->approver()->create(['location_id' => $this->location->id]);

        $this->stale($this->staff());

        $this->assertSame(1, app(ApprovalEscalation::class)->run());
        $this->assertSame(0, app(ApprovalEscalation::class)->run());
    }

    public function test_a_request_still_inside_the_stretch_is_left_alone(): void
    {
        Notification::fake();

        User::factory()->superAdmin()->create();

        $this->stale($this->staff(), daysOld: 1);

        $this->assertSame(0, app(ApprovalEscalation::class)->run());
        Notification::assertNothingSent();
    }

    public function test_a_module_with_no_stretch_set_is_never_chased(): void
    {
        Notification::fake();

        ApprovalSetting::for(RequestModule::Leave)->update(['escalation_hours' => null]);

        User::factory()->superAdmin()->create();
        $this->stale($this->staff(), daysOld: 30);

        $this->assertSame(0, app(ApprovalEscalation::class)->run());
        Notification::assertNothingSent();
    }

    public function test_the_requester_is_not_told_their_own_request_is_overdue(): void
    {
        Notification::fake();

        // They hold the people-team permission and raised the request
        // themselves, which is not a reason to chase themselves.
        $requester = User::factory()->superAdmin()->create(['location_id' => $this->location->id]);

        $this->stale($requester);

        app(ApprovalEscalation::class)->run();

        Notification::assertNotSentTo($requester, ApprovalOverdue::class);
    }

    public function test_a_decided_request_is_never_chased(): void
    {
        Notification::fake();

        User::factory()->superAdmin()->create();

        $leave = $this->stale($this->staff());
        $leave->forceFill(['status' => 'approved', 'decided_at' => Carbon::now()])->save();

        $this->assertSame(0, app(ApprovalEscalation::class)->run());
    }

    public function test_the_command_runs_the_chase(): void
    {
        Notification::fake();

        User::factory()->superAdmin()->create();
        User::factory()->approver()->create(['location_id' => $this->location->id]);

        $this->stale($this->staff());

        $this->artisan('approvals:escalate')
            ->expectsOutputToContain('Chased 1 request(s).')
            ->assertSuccessful();
    }
}
