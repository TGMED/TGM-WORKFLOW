<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\ApprovalSetting;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create(['workdays' => [1, 2, 3, 4, 5]]);
    }

    private function staff(): User
    {
        return User::factory()->create(['location_id' => $this->location->id]);
    }

    /**
     * The two people every request has to name: an approver to rule on it and
     * a colleague to cover the desk.
     *
     * @return array<string, int>
     */
    private function chain(): array
    {
        return [
            'supervisor_id' => User::factory()->approver()->create()->id,
            'relief_officer_id' => $this->staff()->id,
        ];
    }

    private function annual(): LeaveType
    {
        return LeaveType::query()->where('slug', 'annual')->firstOrFail();
    }

    public function test_the_two_shipped_leave_types_are_available(): void
    {
        $slugs = LeaveType::query()->active()->pluck('slug')->all();

        $this->assertContains('annual', $slugs);
        $this->assertContains('sick', $slugs);
    }

    public function test_staff_can_request_leave(): void
    {
        $staff = $this->staff();
        // A Monday to the Wednesday after it: three working days.
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $this->actingAs($staff)
            ->post('/leave', [
                ...$this->chain(),
                'leave_type_id' => $this->annual()->id,
                'start_date' => $monday->toDateString(),
                'end_date' => $monday->copy()->addDays(2)->toDateString(),
                'reason' => 'Family wedding upcountry.',
            ])
            ->assertSessionHasNoErrors();

        $leave = LeaveRequest::query()->firstOrFail();

        $this->assertSame($staff->id, $leave->user_id);
        $this->assertSame(3, $leave->days);
        $this->assertSame(RequestStatus::Pending, $leave->status);
        $this->assertSame(1, $leave->approvals_required);
    }

    public function test_weekends_do_not_count_towards_the_days_taken(): void
    {
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $this->actingAs($this->staff())
            ->post('/leave', [
                ...$this->chain(),
                'leave_type_id' => $this->annual()->id,
                'start_date' => $monday->toDateString(),
                // Monday through the following Friday spans 12 calendar days
                // but only 10 working ones.
                'end_date' => $monday->copy()->addDays(11)->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(10, LeaveRequest::query()->firstOrFail()->days);
    }

    public function test_a_request_beyond_the_yearly_allowance_is_rejected(): void
    {
        $type = LeaveType::factory()->create(['days_per_year' => 5]);
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $this->actingAs($this->staff())
            ->post('/leave', [
                ...$this->chain(),
                'leave_type_id' => $type->id,
                'start_date' => $monday->toDateString(),
                'end_date' => $monday->copy()->addDays(11)->toDateString(),
            ])
            ->assertSessionHasErrors('leave_type_id');

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_leave_cannot_overlap_an_existing_request(): void
    {
        $staff = $this->staff();
        $monday = Carbon::now()->addWeek()->startOfWeek();

        LeaveRequest::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $this->annual()->id,
            'start_date' => $monday,
            'end_date' => $monday->copy()->addDays(4),
            'days' => 5,
        ]);

        $this->actingAs($staff)
            ->post('/leave', [
                ...$this->chain(),
                'leave_type_id' => $this->annual()->id,
                'start_date' => $monday->copy()->addDays(2)->toDateString(),
                'end_date' => $monday->copy()->addDays(6)->toDateString(),
            ])
            ->assertSessionHasErrors('start_date');

        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_a_retired_leave_type_cannot_be_requested(): void
    {
        $type = LeaveType::factory()->retired()->create();
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $this->actingAs($this->staff())
            ->post('/leave', [
                ...$this->chain(),
                'leave_type_id' => $type->id,
                'start_date' => $monday->toDateString(),
                'end_date' => $monday->toDateString(),
            ])
            ->assertSessionHasErrors('leave_type_id');
    }

    public function test_dates_with_no_working_days_are_rejected(): void
    {
        $saturday = Carbon::now()->addWeek()->startOfWeek()->addDays(5);

        $this->actingAs($this->staff())
            ->post('/leave', [
                ...$this->chain(),
                'leave_type_id' => $this->annual()->id,
                'start_date' => $saturday->toDateString(),
                'end_date' => $saturday->copy()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('start_date');
    }

    public function test_the_approver_count_is_snapshotted_when_the_request_is_raised(): void
    {
        ApprovalSetting::query()->where('module', 'leave')->update(['approvers_required' => 3]);

        $monday = Carbon::now()->addWeek()->startOfWeek();

        $this->actingAs($this->staff())
            ->post('/leave', [
                ...$this->chain(),
                'leave_type_id' => $this->annual()->id,
                'start_date' => $monday->toDateString(),
                'end_date' => $monday->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $leave = LeaveRequest::query()->firstOrFail();

        ApprovalSetting::query()->where('module', 'leave')->update(['approvers_required' => 1]);

        $this->assertSame(3, $leave->refresh()->approvals_required);
    }

    public function test_staff_can_withdraw_a_pending_request(): void
    {
        $staff = $this->staff();
        $leave = LeaveRequest::factory()->create(['user_id' => $staff->id]);

        $this->actingAs($staff)->delete("/leave/{$leave->id}");

        $this->assertSame(RequestStatus::Cancelled, $leave->refresh()->status);
    }

    public function test_staff_cannot_withdraw_someone_elses_request(): void
    {
        $leave = LeaveRequest::factory()->create();

        $this->actingAs($this->staff())
            ->delete("/leave/{$leave->id}")
            ->assertForbidden();

        $this->assertSame(RequestStatus::Pending, $leave->refresh()->status);
    }

    public function test_a_decided_request_cannot_be_withdrawn(): void
    {
        $staff = $this->staff();
        $leave = LeaveRequest::factory()->create([
            'user_id' => $staff->id,
            'status' => RequestStatus::Approved,
        ]);

        $this->actingAs($staff)->delete("/leave/{$leave->id}");

        $this->assertSame(RequestStatus::Approved, $leave->refresh()->status);
    }

    public function test_the_leave_page_shows_balances_net_of_pending_requests(): void
    {
        $staff = $this->staff();
        $annual = $this->annual();

        LeaveRequest::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $annual->id,
            'start_date' => Carbon::now()->startOfYear()->addMonth(),
            'end_date' => Carbon::now()->startOfYear()->addMonth()->addDays(4),
            'days' => 4,
        ]);

        $this->actingAs($staff)
            ->get('/leave')
            ->assertInertia(fn ($page) => $page
                ->component('Leave')
                ->where('workdays', [1, 2, 3, 4, 5])
                ->where('balances.0.name', 'Annual leave')
                ->where('balances.0.used', 4)
                ->where('balances.0.remaining', $annual->days_per_year - 4));
    }

    public function test_a_request_names_its_approver_and_relief_officer(): void
    {
        $staff = $this->staff();
        $supervisor = User::factory()->approver()->create();
        $relief = $this->staff();
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $this->actingAs($staff)
            ->post('/leave', [
                'supervisor_id' => $supervisor->id,
                'relief_officer_id' => $relief->id,
                'leave_type_id' => $this->annual()->id,
                'start_date' => $monday->toDateString(),
                'end_date' => $monday->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $leave = LeaveRequest::query()->firstOrFail();

        $this->assertSame($supervisor->id, $leave->supervisor_id);
        $this->assertSame($relief->id, $leave->relief_officer_id);
        $this->assertFalse($leave->load('approvals')->reliefAgreed());
    }

    public function test_the_named_approver_must_be_able_to_approve(): void
    {
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $this->actingAs($this->staff())
            ->post('/leave', [
                'supervisor_id' => $this->staff()->id,
                'relief_officer_id' => $this->staff()->id,
                'leave_type_id' => $this->annual()->id,
                'start_date' => $monday->toDateString(),
                'end_date' => $monday->toDateString(),
            ])
            ->assertSessionHasErrors('supervisor_id');
    }

    public function test_the_relief_officer_cannot_also_be_the_approver(): void
    {
        $supervisor = User::factory()->approver()->create();
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $this->actingAs($this->staff())
            ->post('/leave', [
                'supervisor_id' => $supervisor->id,
                'relief_officer_id' => $supervisor->id,
                'leave_type_id' => $this->annual()->id,
                'start_date' => $monday->toDateString(),
                'end_date' => $monday->toDateString(),
            ])
            ->assertSessionHasErrors('supervisor_id');
    }

    public function test_nobody_covers_or_approves_their_own_leave(): void
    {
        $staff = $this->staff();
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $this->actingAs($staff)
            ->post('/leave', [
                'supervisor_id' => User::factory()->approver()->create()->id,
                'relief_officer_id' => $staff->id,
                'leave_type_id' => $this->annual()->id,
                'start_date' => $monday->toDateString(),
                'end_date' => $monday->toDateString(),
            ])
            ->assertSessionHasErrors('relief_officer_id');
    }

    public function test_a_relief_officer_cannot_book_leave_over_cover_they_agreed(): void
    {
        $cover = $this->staff();
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $covered = LeaveRequest::factory()
            ->chained($cover, User::factory()->approver()->create())
            ->create([
                'user_id' => $this->staff()->id,
                'leave_type_id' => $this->annual()->id,
                'start_date' => $monday,
                'end_date' => $monday->copy()->addDays(4),
                'days' => 5,
            ]);

        // Nothing owed until the cover has actually been agreed.
        $this->actingAs($cover)
            ->post('/leave', [
                ...$this->chain(),
                'leave_type_id' => $this->annual()->id,
                'start_date' => $monday->copy()->addDays(2)->toDateString(),
                'end_date' => $monday->copy()->addDays(2)->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        LeaveRequest::query()->where('user_id', $cover->id)->delete();

        $this->actingAs($cover)->post("/approvals/leave/{$covered->id}", ['decision' => 'approved']);

        $this->actingAs($cover)
            ->post('/leave', [
                ...$this->chain(),
                'leave_type_id' => $this->annual()->id,
                'start_date' => $monday->copy()->addDays(2)->toDateString(),
                'end_date' => $monday->copy()->addDays(2)->toDateString(),
            ])
            ->assertSessionHasErrors('start_date');

        // Days clear of the cover are still theirs to take.
        $this->actingAs($cover)
            ->post('/leave', [
                ...$this->chain(),
                'leave_type_id' => $this->annual()->id,
                'start_date' => $monday->copy()->addDays(7)->toDateString(),
                'end_date' => $monday->copy()->addDays(7)->toDateString(),
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_someone_away_themselves_cannot_be_the_relief_officer(): void
    {
        $colleague = $this->staff();
        $monday = Carbon::now()->addWeek()->startOfWeek();

        LeaveRequest::factory()->create([
            'user_id' => $colleague->id,
            'leave_type_id' => $this->annual()->id,
            'start_date' => $monday,
            'end_date' => $monday->copy()->addDays(4),
            'days' => 5,
        ]);

        $this->actingAs($this->staff())
            ->post('/leave', [
                'supervisor_id' => User::factory()->approver()->create()->id,
                'relief_officer_id' => $colleague->id,
                'leave_type_id' => $this->annual()->id,
                'start_date' => $monday->copy()->addDays(2)->toDateString(),
                'end_date' => $monday->copy()->addDays(3)->toDateString(),
            ])
            ->assertSessionHasErrors('relief_officer_id');
    }

    public function test_the_relief_list_carries_the_leave_each_colleague_has_booked(): void
    {
        $colleague = $this->staff();
        $monday = Carbon::now()->addWeek()->startOfWeek();

        LeaveRequest::factory()->create([
            'user_id' => $colleague->id,
            'leave_type_id' => $this->annual()->id,
            'start_date' => $monday,
            'end_date' => $monday->copy()->addDays(4),
            'days' => 5,
        ]);

        $this->actingAs($this->staff())
            ->get('/leave')
            ->assertInertia(fn ($page) => $page
                ->component('Leave')
                ->whereContains('relief_officers', fn ($option) => $option['value'] === $colleague->id
                    && $option['away'] === [[
                        'start' => $monday->toDateString(),
                        'end' => $monday->copy()->addDays(4)->toDateString(),
                    ]]));
    }

    public function test_the_leave_page_offers_approvers_and_colleagues_to_pick_from(): void
    {
        $staff = $this->staff();
        $supervisor = User::factory()->approver()->create(['name' => 'Ada Approver']);
        $colleague = $this->staff();

        $this->actingAs($staff)
            ->get('/leave')
            ->assertInertia(fn ($page) => $page
                ->component('Leave')
                ->where('supervisors.0.value', $supervisor->id)
                ->has('relief_officers', 2)
                ->whereContains('relief_officers', fn ($option) => $option['value'] === $colleague->id));
    }

    public function test_administrators_do_not_raise_leave_requests(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/leave')
            ->assertRedirect();
    }
}
