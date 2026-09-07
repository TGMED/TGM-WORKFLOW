<?php

namespace Tests\Feature;

use App\Enums\LeaveAnchor;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\User;
use App\Services\LeaveEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The eligibility rules the HR policy attaches to a kind of leave: time
 * served, confirmation in post, the larger entitlement managers draw, the
 * paperwork some types will not go without, and the entitlement that lapses
 * if it is not taken.
 */
class LeavePolicyRulesTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create(['workdays' => [1, 2, 3, 4, 5]]);
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create([
            'location_id' => $this->location->id,
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function chain(): array
    {
        return [
            'supervisor_id' => User::factory()->approver()->create()->id,
            'relief_officer_id' => $this->staff()->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $typeId, ?Carbon $start = null, int $days = 0): array
    {
        $start ??= Carbon::now()->addWeek()->startOfWeek();

        return [
            ...$this->chain(),
            'leave_type_id' => $typeId,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays($days)->toDateString(),
        ];
    }

    private function type(string $slug): LeaveType
    {
        return LeaveType::query()->where('slug', $slug)->firstOrFail();
    }

    // Length of service.

    public function test_a_type_is_closed_until_the_service_it_asks_for_is_served(): void
    {
        $staff = $this->staff(['hired_at' => Carbon::now()->subMonths(4)]);

        $this->actingAs($staff)
            ->post('/leave', $this->payload($this->type('annual')->id))
            ->assertSessionHasErrors('leave_type_id');

        $this->assertSame(0, LeaveRequest::query()->count());
        $this->assertStringContainsString(
            'opens up after 1 year of service',
            session('errors')->get('leave_type_id')[0],
        );
    }

    public function test_the_same_type_opens_once_the_service_is_served(): void
    {
        $staff = $this->staff(['hired_at' => Carbon::now()->subMonths(13)]);

        $this->actingAs($staff)
            ->post('/leave', $this->payload($this->type('annual')->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_a_service_rule_stands_aside_when_no_start_date_is_on_file(): void
    {
        $staff = $this->staff(['hired_at' => null]);

        $this->actingAs($staff)
            ->post('/leave', $this->payload($this->type('annual')->id))
            ->assertSessionHasNoErrors();
    }

    // Probation.

    public function test_a_confirmed_only_type_is_closed_to_staff_on_probation(): void
    {
        $staff = User::factory()->onProbation()->create(['location_id' => $this->location->id]);
        $type = LeaveType::factory()->confirmedOnly()->create();

        $this->actingAs($staff)
            ->post('/leave', $this->payload($type->id))
            ->assertSessionHasErrors('leave_type_id');

        $this->assertStringContainsString(
            'is for confirmed staff',
            session('errors')->get('leave_type_id')[0],
        );
    }

    public function test_a_confirmed_only_type_is_open_to_confirmed_staff(): void
    {
        $type = LeaveType::factory()->confirmedOnly()->create();

        $this->actingAs($this->staff())
            ->post('/leave', $this->payload($type->id))
            ->assertSessionHasNoErrors();
    }

    // Role-based entitlement.

    public function test_managers_draw_the_larger_allowance(): void
    {
        $type = LeaveType::factory()->managerAllowance(15)->create(['days_per_year' => 10]);
        $manager = User::factory()->approver()->create(['location_id' => $this->location->id]);
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $this->assertSame(15, $type->allowanceFor($manager));

        // Twelve working days: over the ten everyone else gets, inside the
        // fifteen a manager gets.
        $this->actingAs($manager)
            ->post('/leave', $this->payload($type->id, $monday, days: 15))
            ->assertSessionHasNoErrors();

        $this->assertSame(12, LeaveRequest::query()->firstOrFail()->days);
    }

    public function test_everyone_else_draws_the_smaller_allowance(): void
    {
        $type = LeaveType::factory()->managerAllowance(15)->create(['days_per_year' => 10]);
        $staff = $this->staff();
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $this->assertSame(10, $type->allowanceFor($staff));

        $this->actingAs($staff)
            ->post('/leave', $this->payload($type->id, $monday, days: 15))
            ->assertSessionHasErrors('leave_type_id');
    }

    // Evidence.

    public function test_a_type_that_requires_evidence_is_turned_away_without_it(): void
    {
        $type = LeaveType::factory()->needsEvidence()->create();

        $this->actingAs($this->staff())
            ->post('/leave', $this->payload($type->id))
            ->assertSessionHasErrors('evidence');

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_evidence_is_stored_off_the_public_disk(): void
    {
        Storage::fake(LeaveEvidence::DISK);

        $type = LeaveType::factory()->needsEvidence()->create();

        $this->actingAs($this->staff())
            ->post('/leave', [
                ...$this->payload($type->id),
                'evidence' => UploadedFile::fake()->create('sick-note.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $leave = LeaveRequest::query()->firstOrFail();

        $this->assertTrue($leave->hasEvidence());
        $this->assertSame('sick-note.pdf', $leave->evidence_name);
        Storage::disk(LeaveEvidence::DISK)->assertExists($leave->evidence_path);
    }

    public function test_only_the_people_concerned_can_open_the_evidence(): void
    {
        Storage::fake(LeaveEvidence::DISK);

        $type = LeaveType::factory()->needsEvidence()->create();
        $staff = $this->staff();

        $this->actingAs($staff)->post('/leave', [
            ...$this->payload($type->id),
            'evidence' => UploadedFile::fake()->create('sick-note.pdf', 40, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $leave = LeaveRequest::query()->firstOrFail();

        $this->actingAs($staff)->get("/leave/{$leave->id}/evidence")->assertOk();

        // A colleague with no part in the request has no business in somebody
        // else's medical paperwork.
        $this->actingAs($this->staff())
            ->get("/leave/{$leave->id}/evidence")
            ->assertForbidden();
    }

    public function test_an_approver_filing_for_somebody_else_is_not_blocked_on_paperwork(): void
    {
        $type = LeaveType::factory()->needsEvidence()->create();
        $staff = $this->staff();
        $approver = User::factory()->approver()->create(['location_id' => $this->location->id]);

        $this->actingAs($approver)
            ->post('/approvals/on-behalf/leave', [
                ...$this->payload($type->id),
                'staff_id' => $staff->id,
                'supervisor_id' => User::factory()->approver()->create()->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, LeaveRequest::query()->count());
    }

    // Entitlement that lapses.

    public function test_an_expiring_entitlement_is_refused_outside_its_window(): void
    {
        $type = LeaveType::factory()->expiring(LeaveAnchor::Birthday, 6)->create(['days_per_year' => 1]);
        $staff = $this->staff();

        // A birthday eight months back puts the six-month window behind us.
        $staff->profile->update(['date_of_birth' => Carbon::now()->subMonths(8)]);

        $this->actingAs($staff)
            ->post('/leave', $this->payload($type->id))
            ->assertSessionHasErrors('start_date');

        $this->assertStringContainsString(
            'within 6 months of their birthday',
            session('errors')->get('start_date')[0],
        );
    }

    public function test_an_expiring_entitlement_goes_through_inside_its_window(): void
    {
        $type = LeaveType::factory()->expiring(LeaveAnchor::Birthday, 6)->create(['days_per_year' => 1]);
        $staff = $this->staff();

        $staff->profile->update(['date_of_birth' => Carbon::now()->subMonth()]);

        $this->actingAs($staff)
            ->post('/leave', $this->payload($type->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_a_window_stands_aside_when_the_record_has_no_date_to_hang_it_on(): void
    {
        $type = LeaveType::factory()->expiring(LeaveAnchor::Birthday, 6)->create(['days_per_year' => 1]);
        $staff = $this->staff();

        $staff->profile->update(['date_of_birth' => null]);

        $this->actingAs($staff)
            ->post('/leave', $this->payload($type->id))
            ->assertSessionHasNoErrors();
    }

    // What the leave page is told.

    public function test_the_leave_page_says_why_a_type_is_closed(): void
    {
        $staff = $this->staff(['hired_at' => Carbon::now()->subMonths(3)]);

        $this->actingAs($staff)
            ->get('/leave')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Leave')
                ->where(
                    'balances',
                    fn ($balances): bool => collect($balances)
                        ->firstWhere('slug', 'annual')['eligible'] === false,
                )
                ->etc());
    }
}
