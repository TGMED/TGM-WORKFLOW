<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WhoIsAwayTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
        $this->type = LeaveType::factory()->create(['name' => 'Annual leave']);
    }

    private function leave(User $user, string $start, string $end, RequestStatus $status = RequestStatus::Approved): LeaveRequest
    {
        return LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type_id' => $this->type->id,
            'start_date' => $start,
            'end_date' => $end,
            'days' => 1,
            'status' => $status,
        ]);
    }

    public function test_the_roster_lists_people_whose_approved_leave_covers_today(): void
    {
        $viewer = User::factory()->create(['location_id' => $this->location->id]);
        $away = User::factory()->create(['name' => 'Ada Away', 'location_id' => $this->location->id]);
        $working = User::factory()->create(['name' => 'Ben Busy', 'location_id' => $this->location->id]);

        $this->leave($away, Carbon::now()->subDay()->toDateString(), Carbon::now()->addDay()->toDateString());
        $this->leave($working, Carbon::now()->addWeek()->toDateString(), Carbon::now()->addWeek()->addDay()->toDateString());

        $this->actingAs($viewer)
            ->get('/away')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WhoIsAway')
                ->where('stats.away_today', 1)
                ->has('people', 1)
                ->where('people.0.user.name', 'Ada Away')
                ->where('people.0.is_away', true)
            );
    }

    /**
     * Requests nobody has ruled on yet are not leave, so they stay off the
     * roster even though they are counted as outstanding.
     */
    public function test_pending_leave_is_counted_but_not_rostered(): void
    {
        $viewer = User::factory()->create(['location_id' => $this->location->id]);
        $maybe = User::factory()->create(['location_id' => $this->location->id]);

        $this->leave(
            $maybe,
            Carbon::now()->toDateString(),
            Carbon::now()->addDay()->toDateString(),
            RequestStatus::Pending,
        );

        $this->actingAs($viewer)
            ->get('/away')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.away_today', 0)
                ->where('stats.pending', 1)
                ->has('people', 0)
            );
    }

    public function test_a_wider_window_picks_up_leave_that_has_not_started(): void
    {
        $viewer = User::factory()->create(['location_id' => $this->location->id]);
        $soon = User::factory()->create(['location_id' => $this->location->id]);

        $this->leave(
            $soon,
            Carbon::now()->addDays(3)->toDateString(),
            Carbon::now()->addDays(4)->toDateString(),
        );

        $this->actingAs($viewer)->get('/away')->assertInertia(fn ($page) => $page->has('people', 0));

        $this->actingAs($viewer)
            ->get('/away?window=7d')
            ->assertInertia(fn ($page) => $page
                ->has('people', 1)
                ->where('people.0.is_away', false)
                ->where('people.0.starts_in', 3)
            );
    }

    public function test_the_dashboard_counts_colleagues_away_today(): void
    {
        $viewer = User::factory()->create(['location_id' => $this->location->id]);
        $away = User::factory()->create(['name' => 'Ada Away', 'location_id' => $this->location->id]);

        $this->leave($away, Carbon::now()->toDateString(), Carbon::now()->toDateString());
        // The viewer's own leave is not a colleague being away.
        $this->leave($viewer, Carbon::now()->toDateString(), Carbon::now()->toDateString());

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('away.today', 1)
                ->where('away.names', ['Ada Away'])
            );
    }
}
