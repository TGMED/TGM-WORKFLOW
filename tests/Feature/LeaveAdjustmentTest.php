<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\LeaveAdjustment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\User;
use App\Services\LeaveBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeaveAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
        $this->annual = LeaveType::query()->where('slug', 'annual')->firstOrFail();
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function staff(): User
    {
        return User::factory()->create(['location_id' => $this->location->id]);
    }

    private function balanceFor(User $user): array
    {
        return app(LeaveBalance::class)->forType($user, $this->annual, Carbon::now()->year);
    }

    public function test_an_admin_can_add_days_to_one_persons_allowance(): void
    {
        $staff = $this->staff();

        $this->actingAs($this->admin())
            ->post("/admin/staff/{$staff->id}/leave-adjustments", [
                'leave_type_id' => $this->annual->id,
                'year' => Carbon::now()->year,
                'days' => 5,
                'reason' => 'Carried over from last year',
            ])
            ->assertSessionHasNoErrors();

        $balance = $this->balanceFor($staff);

        $this->assertSame(25, $balance['allowance']);
        $this->assertSame(5, $balance['adjusted']);
        $this->assertSame(25, $balance['remaining']);
    }

    public function test_days_can_be_taken_off_an_allowance(): void
    {
        $staff = $this->staff();

        $this->actingAs($this->admin())
            ->post("/admin/staff/{$staff->id}/leave-adjustments", [
                'leave_type_id' => $this->annual->id,
                'year' => Carbon::now()->year,
                'days' => -8,
                'reason' => 'Left mid-year, allowance pro-rated',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(12, $this->balanceFor($staff)['allowance']);
    }

    public function test_adjustments_stack_and_only_touch_the_one_person(): void
    {
        $staff = $this->staff();
        $colleague = $this->staff();

        LeaveAdjustment::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $this->annual->id,
            'year' => Carbon::now()->year,
            'days' => 3,
        ]);

        LeaveAdjustment::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $this->annual->id,
            'year' => Carbon::now()->year,
            'days' => -1,
        ]);

        $this->assertSame(22, $this->balanceFor($staff)['allowance']);
        // The colleague is still on the leave type's standard figure.
        $this->assertSame(20, $this->balanceFor($colleague)['allowance']);
    }

    public function test_an_adjustment_only_counts_in_its_own_year(): void
    {
        $staff = $this->staff();

        LeaveAdjustment::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $this->annual->id,
            'year' => Carbon::now()->year - 1,
            'days' => 6,
        ]);

        $this->assertSame(20, $this->balanceFor($staff)['allowance']);
    }

    public function test_days_already_taken_come_off_the_adjusted_allowance(): void
    {
        $staff = $this->staff();

        LeaveRequest::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $this->annual->id,
            'days' => 4,
            'status' => RequestStatus::Approved,
        ]);

        LeaveAdjustment::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $this->annual->id,
            'year' => Carbon::now()->year,
            'days' => 5,
        ]);

        $balance = $this->balanceFor($staff);

        $this->assertSame(25, $balance['allowance']);
        $this->assertSame(4, $balance['used']);
        $this->assertSame(21, $balance['remaining']);
    }

    public function test_a_granted_adjustment_lets_someone_book_beyond_the_standard_allowance(): void
    {
        $staff = $this->staff();

        LeaveRequest::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $this->annual->id,
            'days' => 20,
            'status' => RequestStatus::Approved,
        ]);

        $this->assertSame(0, $this->balanceFor($staff)['remaining']);

        LeaveAdjustment::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $this->annual->id,
            'year' => Carbon::now()->year,
            'days' => 4,
        ]);

        $this->assertSame(4, $this->balanceFor($staff)['remaining']);
    }

    public function test_a_deduction_below_what_is_already_taken_floors_at_nothing_left(): void
    {
        $staff = $this->staff();

        LeaveRequest::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $this->annual->id,
            'days' => 15,
            'status' => RequestStatus::Approved,
        ]);

        LeaveAdjustment::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $this->annual->id,
            'year' => Carbon::now()->year,
            'days' => -12,
        ]);

        $balance = $this->balanceFor($staff);

        $this->assertSame(8, $balance['allowance']);
        $this->assertSame(0, $balance['remaining']);
    }

    public function test_an_uncapped_type_cannot_be_adjusted(): void
    {
        $staff = $this->staff();
        $uncapped = LeaveType::factory()->uncapped()->create();

        $this->actingAs($this->admin())
            ->post("/admin/staff/{$staff->id}/leave-adjustments", [
                'leave_type_id' => $uncapped->id,
                'year' => Carbon::now()->year,
                'days' => 5,
                'reason' => 'Pointless',
            ])
            ->assertSessionHasErrors('leave_type_id');
    }

    public function test_an_adjustment_needs_days_and_a_reason(): void
    {
        $staff = $this->staff();

        $this->actingAs($this->admin())
            ->post("/admin/staff/{$staff->id}/leave-adjustments", [
                'leave_type_id' => $this->annual->id,
                'year' => Carbon::now()->year,
                'days' => 0,
                'reason' => '',
            ])
            ->assertSessionHasErrors(['days', 'reason']);
    }

    public function test_an_adjustment_cannot_be_backdated_years(): void
    {
        $staff = $this->staff();

        $this->actingAs($this->admin())
            ->post("/admin/staff/{$staff->id}/leave-adjustments", [
                'leave_type_id' => $this->annual->id,
                'year' => Carbon::now()->year - 5,
                'days' => 3,
                'reason' => 'Ancient history',
            ])
            ->assertSessionHasErrors('year');
    }

    public function test_an_adjustment_records_who_made_it(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();

        $this->actingAs($admin)->post("/admin/staff/{$staff->id}/leave-adjustments", [
            'leave_type_id' => $this->annual->id,
            'year' => Carbon::now()->year,
            'days' => 2,
            'reason' => 'Time off in lieu',
        ]);

        $adjustment = LeaveAdjustment::query()->where('user_id', $staff->id)->firstOrFail();

        $this->assertSame($admin->id, $adjustment->created_by);
        $this->assertSame('Time off in lieu', $adjustment->reason);
    }

    public function test_an_adjustment_can_be_undone(): void
    {
        $staff = $this->staff();

        $adjustment = LeaveAdjustment::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $this->annual->id,
            'year' => Carbon::now()->year,
            'days' => 5,
        ]);

        $this->actingAs($this->admin())
            ->delete("/admin/staff/{$staff->id}/leave-adjustments/{$adjustment->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('leave_adjustments', ['id' => $adjustment->id]);
        $this->assertSame(20, $this->balanceFor($staff)['allowance']);
    }

    public function test_an_adjustment_cannot_be_undone_through_another_persons_record(): void
    {
        $staff = $this->staff();
        $colleague = $this->staff();

        $adjustment = LeaveAdjustment::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $this->annual->id,
            'year' => Carbon::now()->year,
        ]);

        $this->actingAs($this->admin())
            ->delete("/admin/staff/{$colleague->id}/leave-adjustments/{$adjustment->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('leave_adjustments', ['id' => $adjustment->id]);
    }

    public function test_staff_cannot_adjust_their_own_balance(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post("/admin/staff/{$staff->id}/leave-adjustments", [
                'leave_type_id' => $this->annual->id,
                'year' => Carbon::now()->year,
                'days' => 50,
                'reason' => 'Generous',
            ])
            ->assertForbidden();

        $this->assertSame(20, $this->balanceFor($staff)['allowance']);
    }

    public function test_the_staff_page_shows_the_balance_and_its_adjustments(): void
    {
        $staff = $this->staff();

        LeaveAdjustment::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $this->annual->id,
            'year' => Carbon::now()->year,
            'days' => 3,
            'reason' => 'Carried over from last year',
        ]);

        $this->actingAs($this->admin())
            ->get("/admin/staff/{$staff->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('leave_year', Carbon::now()->year)
                ->has('adjustments', 1)
                ->where('adjustments.0.signed_days', '+3')
                ->where('adjustments.0.reason', 'Carried over from last year')
                ->where('balances.0.adjusted', 3));
    }
}
