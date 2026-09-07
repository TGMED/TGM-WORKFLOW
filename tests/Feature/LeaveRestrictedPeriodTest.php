<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\LeaveRestrictedPeriod;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Stretches of the calendar the business closes to leave, and the two ways
 * through one: the marital statuses it exempts, and the leave types it does
 * not cover.
 */
class LeaveRestrictedPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create(['workdays' => [1, 2, 3, 4, 5]]);
    }

    private function staff(?string $maritalStatus = null): User
    {
        $staff = User::factory()->create(['location_id' => $this->location->id]);

        // Everyone comes with a finished profile, so the status is set on the
        // one they already have. Null is the person who never filled it in.
        $staff->profile()->update(['marital_status' => $maritalStatus]);

        return $staff;
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function annual(): LeaveType
    {
        return LeaveType::query()->where('slug', 'annual')->firstOrFail();
    }

    /**
     * A type to let through a closed period. Personal leave, because these
     * tests are about the period and not about the paperwork some other types
     * ask for.
     */
    private function exempted(): LeaveType
    {
        return LeaveType::query()->where('slug', 'personal')->firstOrFail();
    }

    /**
     * The Monday of the week after next, and the two days that follow it.
     */
    private function monday(): Carbon
    {
        return Carbon::now()->addWeeks(2)->startOfWeek();
    }

    /**
     * A period closed over the days every payload below asks for.
     *
     * @param  array<int, string>  $exempt
     */
    private function period(array $exempt = []): LeaveRestrictedPeriod
    {
        return LeaveRestrictedPeriod::factory()->create([
            'name' => 'Stock count',
            'reason' => 'Every hand is needed on the floor.',
            'start_date' => $this->monday()->subDay(),
            'end_date' => $this->monday()->addWeek(),
            'exempt_marital_statuses' => $exempt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?int $typeId = null): array
    {
        return [
            'leave_type_id' => $typeId ?? $this->annual()->id,
            'supervisor_id' => User::factory()->approver()->create()->id,
            'relief_officer_id' => $this->staff()->id,
            'start_date' => $this->monday()->toDateString(),
            'end_date' => $this->monday()->addDays(2)->toDateString(),
            'reason' => 'Family wedding upcountry.',
        ];
    }

    public function test_leave_cannot_be_booked_over_a_restricted_period(): void
    {
        $this->period();

        $this->actingAs($this->staff('Single'))
            ->post('/leave', $this->payload())
            ->assertSessionHasErrors('start_date');

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_the_rejection_says_which_period_stands_in_the_way(): void
    {
        $this->period();

        $this->actingAs($this->staff('Single'))
            ->post('/leave', $this->payload())
            ->assertSessionHasErrors('start_date');

        $errors = session('errors')->get('start_date');

        $this->assertStringContainsString('Stock count', $errors[0]);
        $this->assertStringContainsString('Every hand is needed on the floor.', $errors[0]);
    }

    public function test_a_request_that_only_clips_the_edge_of_a_period_is_turned_away(): void
    {
        // Closed for the single day the request's last day lands on.
        LeaveRestrictedPeriod::factory()->create([
            'start_date' => $this->monday()->addDays(2),
            'end_date' => $this->monday()->addDays(2),
        ]);

        $this->actingAs($this->staff('Single'))
            ->post('/leave', $this->payload())
            ->assertSessionHasErrors('start_date');
    }

    public function test_leave_outside_a_restricted_period_is_untouched(): void
    {
        LeaveRestrictedPeriod::factory()->create([
            'start_date' => $this->monday()->addMonth(),
            'end_date' => $this->monday()->addMonth()->addWeek(),
        ]);

        $this->actingAs($this->staff('Single'))
            ->post('/leave', $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_an_exempt_marital_status_may_book_over_the_period(): void
    {
        $this->period(exempt: ['Married']);

        $this->actingAs($this->staff('Married'))
            ->post('/leave', $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_a_status_the_period_does_not_name_is_still_turned_away(): void
    {
        $this->period(exempt: ['Married']);

        $this->actingAs($this->staff('Divorced'))
            ->post('/leave', $this->payload())
            ->assertSessionHasErrors('start_date');
    }

    public function test_a_blank_marital_status_is_no_exemption_and_is_told_to_set_one(): void
    {
        $this->period(exempt: ['Married']);

        $this->actingAs($this->staff())
            ->post('/leave', $this->payload())
            ->assertSessionHasErrors('start_date');

        $errors = session('errors')->get('start_date');

        $this->assertStringContainsString('does not record a marital status', $errors[0]);
        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_an_exempt_leave_type_goes_through_the_period(): void
    {
        $period = $this->period();
        $period->leaveTypes()->sync([$this->exempted()->id]);

        $this->actingAs($this->staff('Single'))
            ->post('/leave', $this->payload($this->exempted()->id))
            ->assertSessionHasNoErrors();

        // The same days on a type the period does cover are refused.
        $this->actingAs($this->staff('Single'))
            ->post('/leave', $this->payload())
            ->assertSessionHasErrors('start_date');
    }

    public function test_an_approver_filing_for_someone_else_can_go_through_a_closed_period(): void
    {
        $this->period();

        $staff = $this->staff('Single');
        $approver = User::factory()->approver()->create(['location_id' => $this->location->id]);

        $this->actingAs($approver)
            ->post('/approvals/on-behalf/leave', [
                ...$this->payload(),
                'staff_id' => $staff->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, LeaveRequest::query()->where('user_id', $staff->id)->count());
    }

    public function test_leave_already_granted_stands_when_a_period_is_opened_over_it(): void
    {
        $leave = LeaveRequest::factory()->create([
            'user_id' => $this->staff('Single')->id,
            'start_date' => $this->monday(),
            'end_date' => $this->monday()->addDays(2),
        ]);

        $this->actingAs($this->admin())
            ->post('/admin/restricted-periods', [
                'name' => 'Stock count',
                'start_date' => $this->monday()->toDateString(),
                'end_date' => $this->monday()->addWeek()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id]);
    }

    public function test_an_administrator_can_open_a_period_with_its_exemptions(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/restricted-periods', [
                'name' => 'Stock count',
                'reason' => 'Every hand is needed on the floor.',
                'start_date' => $this->monday()->toDateString(),
                'end_date' => $this->monday()->addWeek()->toDateString(),
                'exempt_marital_statuses' => ['Married'],
                'exempt_leave_type_ids' => [$this->exempted()->id],
            ])
            ->assertSessionHasNoErrors();

        $period = LeaveRestrictedPeriod::query()->firstOrFail();

        $this->assertSame(['Married'], $period->exempt_marital_statuses);
        $this->assertTrue($period->leaveTypes->contains('id', $this->exempted()->id));
    }

    public function test_a_period_cannot_end_before_it_starts(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/restricted-periods', [
                'name' => 'Stock count',
                'start_date' => $this->monday()->toDateString(),
                'end_date' => $this->monday()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('end_date');
    }

    public function test_a_period_can_only_exempt_a_status_from_the_pick_list(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/restricted-periods', [
                'name' => 'Stock count',
                'start_date' => $this->monday()->toDateString(),
                'end_date' => $this->monday()->addWeek()->toDateString(),
                'exempt_marital_statuses' => ['Betrothed'],
            ])
            ->assertSessionHasErrors('exempt_marital_statuses.0');
    }

    public function test_an_administrator_can_change_and_lift_a_period(): void
    {
        $period = $this->period();
        $period->leaveTypes()->sync([$this->exempted()->id]);

        $this->actingAs($this->admin())
            ->put("/admin/restricted-periods/{$period->id}", [
                'name' => 'Stock count (extended)',
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->addWeek()->toDateString(),
                'exempt_marital_statuses' => [],
                'exempt_leave_type_ids' => [],
            ])
            ->assertSessionHasNoErrors();

        $period->refresh()->load('leaveTypes');

        $this->assertSame('Stock count (extended)', $period->name);
        $this->assertCount(0, $period->leaveTypes);

        $this->actingAs($this->admin())
            ->delete("/admin/restricted-periods/{$period->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('leave_restricted_periods', ['id' => $period->id]);
        $this->assertDatabaseCount('leave_restricted_period_leave_type', 0);
    }

    public function test_staff_cannot_open_a_period(): void
    {
        $this->actingAs($this->staff())
            ->post('/admin/restricted-periods', [
                'name' => 'Stock count',
                'start_date' => $this->monday()->toDateString(),
                'end_date' => $this->monday()->addWeek()->toDateString(),
            ])
            ->assertForbidden();
    }
}
