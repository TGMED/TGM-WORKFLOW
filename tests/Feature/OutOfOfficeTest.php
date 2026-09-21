<?php

namespace Tests\Feature;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStage;
use App\Enums\OutOfOfficeKind;
use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Models\ApprovalSetting;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\OutOfOfficeRequest;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Days worked away from the office. Not leave: nothing comes off an
 * allowance, and the roster shows the person as working.
 */
class OutOfOfficeTest extends TestCase
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
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $monday = Carbon::now()->addWeek()->startOfWeek();

        return [
            'kind' => OutOfOfficeKind::Remote->value,
            'start_date' => $monday->toDateString(),
            'end_date' => $monday->copy()->addDay()->toDateString(),
            'reason' => 'Writing the quarterly report, which needs a quiet room.',
            ...$overrides,
        ];
    }

    public function test_staff_can_ask_to_work_from_home(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post('/out-of-office', $this->payload())
            ->assertSessionHasNoErrors();

        $away = OutOfOfficeRequest::query()->firstOrFail();

        $this->assertSame($staff->id, $away->user_id);
        $this->assertSame(OutOfOfficeKind::Remote, $away->kind);
        $this->assertSame(2, $away->days);
        $this->assertSame(RequestStatus::Pending, $away->status);
    }

    public function test_an_assignment_has_to_say_where(): void
    {
        $this->actingAs($this->staff())
            ->post('/out-of-office', $this->payload([
                'kind' => OutOfOfficeKind::Assignment->value,
            ]))
            ->assertSessionHasErrors('destination');
    }

    public function test_an_assignment_records_where_and_how_to_reach_them(): void
    {
        $this->actingAs($this->staff())
            ->post('/out-of-office', $this->payload([
                'kind' => OutOfOfficeKind::Assignment->value,
                'destination' => 'Port Harcourt branch',
                'contact_number' => '08012345678',
            ]))
            ->assertSessionHasNoErrors();

        $away = OutOfOfficeRequest::query()->firstOrFail();

        $this->assertSame('Port Harcourt branch', $away->destination);
        $this->assertSame('08012345678', $away->contact_number);
    }

    public function test_only_working_days_are_counted(): void
    {
        $saturday = Carbon::now()->addWeek()->startOfWeek()->addDays(5);

        $this->actingAs($this->staff())
            ->post('/out-of-office', $this->payload([
                'start_date' => $saturday->toDateString(),
                'end_date' => $saturday->copy()->addDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('start_date');
    }

    public function test_two_requests_cannot_cover_the_same_day(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post('/out-of-office', $this->payload());

        $this->actingAs($staff)
            ->post('/out-of-office', $this->payload())
            ->assertSessionHasErrors('start_date');

        $this->assertSame(1, OutOfOfficeRequest::query()->count());
    }

    public function test_a_day_already_booked_as_leave_cannot_be_worked_elsewhere(): void
    {
        $staff = $this->staff();
        $monday = Carbon::now()->addWeek()->startOfWeek();

        LeaveRequest::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => LeaveType::query()->where('slug', 'annual')->firstOrFail()->id,
            'start_date' => $monday,
            'end_date' => $monday->copy()->addDays(2),
            'status' => RequestStatus::Approved,
        ]);

        $this->actingAs($staff)
            ->post('/out-of-office', $this->payload())
            ->assertSessionHasErrors('start_date');
    }

    public function test_it_carries_its_own_approver_count(): void
    {
        ApprovalSetting::for(RequestModule::OutOfOffice)->update(['approvers_required' => 2]);

        $this->actingAs($this->staff())->post('/out-of-office', $this->payload());

        $this->assertSame(2, OutOfOfficeRequest::query()->firstOrFail()->approvals_required);
        // Leave is configured separately and is left alone.
        $this->assertSame(1, ApprovalSetting::approversRequired(RequestModule::Leave));
    }

    public function test_an_approver_cannot_agree_it_in_place_of_an_administrator(): void
    {
        $staff = $this->staff();
        $approver = User::factory()->approver()->create(['location_id' => $this->location->id]);

        $this->actingAs($staff)->post('/out-of-office', $this->payload());

        $away = OutOfOfficeRequest::query()->firstOrFail();

        $this->actingAs($approver)
            ->get('/approvals')
            ->assertInertia(fn ($page) => $page->has('out_of_office', 0));

        $this->assertFalse(app(ApprovalService::class)->decide($away, $approver, ApprovalDecision::Approved));
        $this->assertSame(RequestStatus::Pending, $away->refresh()->status);
    }

    public function test_an_administrator_has_the_final_say(): void
    {
        $staff = $this->staff();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($staff)->post('/out-of-office', $this->payload());

        $away = OutOfOfficeRequest::query()->firstOrFail();

        $this->assertSame('With an administrator', $away->stageLabel());

        $this->actingAs($admin)
            ->get('/approvals')
            ->assertInertia(fn ($page) => $page
                ->has('out_of_office', 1)
                ->where('out_of_office.0.stage', 'admin'));

        app(ApprovalService::class)->decide($away, $admin, ApprovalDecision::Approved);

        $away->refresh();
        $this->assertSame(RequestStatus::Approved, $away->status);
        $this->assertSame(ApprovalStage::Admin, $away->approvals->sole()->stage);
    }

    public function test_the_line_goes_first_and_then_the_administrator_is_asked(): void
    {
        Notification::fake();

        $admin = User::factory()->superAdmin()->create();
        $head = $this->staff();
        $staff = $this->staff();

        $this->actingAs($admin)->post('/admin/departments', [
            'name' => 'Operations',
            'head_user_id' => $head->id,
            'members' => [$head->id, $staff->id],
        ])->assertSessionHasNoErrors();

        $this->actingAs($staff->fresh())->post('/out-of-office', $this->payload());

        $away = OutOfOfficeRequest::query()->firstOrFail();

        // The administrator cannot jump the line.
        $this->assertFalse($away->awaitsDecisionFrom($admin));
        $this->assertTrue($away->awaitsDecisionFrom($head->fresh()));

        app(ApprovalService::class)->decide($away, $head->fresh(), ApprovalDecision::Approved);

        // The head's approval meets the count, but it is not agreed yet.
        $away->refresh();
        $this->assertSame(RequestStatus::Pending, $away->status);
        $this->assertTrue($away->awaitsAdmin());
        $this->assertSame('An administrator has the final say.', $away->nextStep());
        Notification::assertSentTo($admin, ApprovalRequested::class);

        app(ApprovalService::class)->decide($away, $admin, ApprovalDecision::Approved);

        $this->assertSame(RequestStatus::Approved, $away->refresh()->status);
    }

    public function test_an_administrator_can_turn_it_down(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($this->staff())->post('/out-of-office', $this->payload());

        $away = OutOfOfficeRequest::query()->firstOrFail();

        app(ApprovalService::class)->decide($away, $admin, ApprovalDecision::Rejected, 'We need you on site that week.');

        $this->assertSame(RequestStatus::Rejected, $away->refresh()->status);
    }

    public function test_a_higher_approval_count_is_still_met_after_the_administrator(): void
    {
        ApprovalSetting::for(RequestModule::OutOfOffice)->update(['approvers_required' => 2]);

        $admin = User::factory()->superAdmin()->create();
        $approver = User::factory()->approver()->create(['location_id' => $this->location->id]);

        $this->actingAs($this->staff())->post('/out-of-office', $this->payload());

        $away = OutOfOfficeRequest::query()->firstOrFail();

        app(ApprovalService::class)->decide($away, $admin, ApprovalDecision::Approved);
        $this->assertSame(RequestStatus::Pending, $away->refresh()->status);

        app(ApprovalService::class)->decide($away, $approver, ApprovalDecision::Approved);
        $this->assertSame(RequestStatus::Approved, $away->refresh()->status);
    }

    public function test_staff_can_withdraw_one_still_waiting(): void
    {
        $staff = $this->staff();
        $away = OutOfOfficeRequest::factory()->create(['user_id' => $staff->id]);

        $this->actingAs($staff)->delete("/out-of-office/{$away->id}");

        $this->assertSame(RequestStatus::Cancelled, $away->refresh()->status);
    }

    public function test_staff_cannot_withdraw_somebody_elses(): void
    {
        $away = OutOfOfficeRequest::factory()->create(['user_id' => $this->staff()->id]);

        $this->actingAs($this->staff())
            ->delete("/out-of-office/{$away->id}")
            ->assertForbidden();
    }

    public function test_the_roster_lists_them_as_working_rather_than_away(): void
    {
        $staff = $this->staff();

        OutOfOfficeRequest::factory()->approved()->create([
            'user_id' => $staff->id,
            'start_date' => Carbon::now()->startOfDay(),
            'end_date' => Carbon::now()->startOfDay(),
            'days' => 1,
        ]);

        $this->actingAs($staff)
            ->get('/away')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('elsewhere', 1)
                ->has('people', 0)
                ->where('stats.away_today', 0)
                ->where('stats.working_elsewhere_today', 1));
    }

    public function test_agreed_days_away_are_not_counted_as_absence(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $staff = $this->staff();

        $monday = Carbon::now()->startOfWeek();

        OutOfOfficeRequest::factory()->approved()->create([
            'user_id' => $staff->id,
            'start_date' => $monday,
            'end_date' => $monday->copy()->addDay(),
            'days' => 2,
        ]);

        $this->actingAs($admin)
            ->get('/admin/attendance?from='.$monday->toDateString().'&to='.$monday->copy()->addDay()->toDateString())
            ->assertOk()
            ->assertInertia(function ($page) use ($staff) {
                $row = collect($page->toArray()['props']['rows']['data'])
                    ->firstWhere('id', $staff->id);

                $this->assertSame(2, $row['days_elsewhere']);
                // Two working days expected, two spent elsewhere, so nothing
                // is owed.
                $this->assertSame(0, $row['days_absent']);
            });
    }
}
