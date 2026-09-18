<?php

namespace Tests\Feature;

use App\Enums\ExitReason;
use App\Models\Location;
use App\Models\User;
use App\Services\StaffExit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Who each person reports to, as a line of its own. Separate from who runs a
 * department, which answers a different question.
 */
class ManagerLineTest extends TestCase
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

    private function payload(User $staff, array $overrides = []): array
    {
        return [
            'name' => $staff->name,
            'email' => $staff->email,
            'roles' => ['staff'],
            'location_id' => $this->location->id,
            ...$overrides,
        ];
    }

    public function test_an_administrator_can_name_who_somebody_reports_to(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $staff = $this->staff();
        $manager = $this->staff();

        $this->actingAs($admin)
            ->put("/admin/staff/{$staff->id}", $this->payload($staff, ['manager_id' => $manager->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($manager->id, $staff->refresh()->manager_id);
        $this->assertTrue($manager->directReports->contains('id', $staff->id));
    }

    public function test_nobody_reports_to_themselves(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $staff = $this->staff();

        $this->actingAs($admin)
            ->put("/admin/staff/{$staff->id}", $this->payload($staff, ['manager_id' => $staff->id]))
            ->assertSessionHasErrors('manager_id');

        $this->assertNull($staff->refresh()->manager_id);
    }

    public function test_the_line_cannot_be_made_to_loop(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $head = $this->staff();
        $middle = $this->staff(['manager_id' => $head->id]);
        $junior = $this->staff(['manager_id' => $middle->id]);

        // Putting the head under the junior would close the circle.
        $this->actingAs($admin)
            ->put("/admin/staff/{$head->id}", $this->payload($head, ['manager_id' => $junior->id]))
            ->assertSessionHasErrors('manager_id');

        $this->assertNull($head->refresh()->manager_id);
    }

    public function test_the_chain_climbs_to_the_top(): void
    {
        $head = $this->staff();
        $middle = $this->staff(['manager_id' => $head->id]);
        $junior = $this->staff(['manager_id' => $middle->id]);

        $this->assertSame(
            [$middle->id, $head->id],
            $junior->managerChain()->pluck('id')->all(),
        );
    }

    public function test_a_looping_chain_does_not_hang(): void
    {
        // Written straight to the table, since nothing in the app will make
        // a loop. A row that somehow does must not take a page down with it.
        $one = $this->staff();
        $two = $this->staff(['manager_id' => $one->id]);
        $one->forceFill(['manager_id' => $two->id])->save();

        // The walk stops the moment it comes back round to where it started,
        // rather than going round again.
        $this->assertSame([$two->id], $one->refresh()->managerChain()->pluck('id')->all());
    }

    public function test_a_departing_manager_passes_their_reports_up_a_rung(): void
    {
        $director = $this->staff();
        $manager = $this->staff(['manager_id' => $director->id]);
        $junior = $this->staff(['manager_id' => $manager->id]);

        $outcome = app(StaffExit::class)->record(
            $manager,
            ExitReason::ResignationWithNotice,
            Carbon::now()->startOfDay(),
        );

        $this->assertSame($director->id, $junior->refresh()->manager_id);
        $this->assertSame(1, $outcome->reportsReassigned);
    }

    public function test_reports_of_a_leaver_with_no_manager_are_left_unplaced(): void
    {
        $manager = $this->staff();
        $junior = $this->staff(['manager_id' => $manager->id]);

        app(StaffExit::class)->record(
            $manager,
            ExitReason::ResignationWithoutNotice,
            Carbon::now()->startOfDay(),
        );

        $this->assertNull($junior->refresh()->manager_id);
    }

    public function test_somebody_who_reports_to_this_person_is_not_offered_as_their_manager(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $staff = $this->staff();
        $report = $this->staff(['manager_id' => $staff->id]);
        $other = $this->staff();

        $this->actingAs($admin)
            ->get("/admin/staff/{$staff->id}")
            ->assertInertia(function ($page) use ($report, $other, $staff) {
                $ids = collect($page->toArray()['props']['managers'])->pluck('value');

                $this->assertFalse($ids->contains($staff->id));
                $this->assertFalse($ids->contains($report->id));
                $this->assertTrue($ids->contains($other->id));
            });
    }
}
