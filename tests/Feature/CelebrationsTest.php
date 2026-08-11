<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CelebrationsTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create(['timezone' => 'Africa/Lagos']);

        // A fixed day well clear of the ends of the month and of February, so
        // the window in these tests never straddles a year or a leap day.
        $this->travelTo(Carbon::parse('2026-06-10 09:00:00', 'Africa/Lagos'));
    }

    /**
     * Someone with no birthday or hire date of their own, so the lists only
     * ever hold the people a test put there.
     */
    private function viewer(): User
    {
        $user = User::factory()->create([
            'location_id' => $this->location->id,
            'hired_at' => Carbon::parse('2020-06-10'),
        ]);

        $user->profile->update(['date_of_birth' => Carbon::parse('1990-06-10')]);

        return $user;
    }

    private function staff(string $birthday, string $hiredAt): User
    {
        $user = User::factory()->create([
            'location_id' => $this->location->id,
            'hired_at' => Carbon::parse($hiredAt),
        ]);

        $user->profile->update(['date_of_birth' => Carbon::parse($birthday)]);

        return $user;
    }

    public function test_the_dashboard_lists_birthdays_and_anniversaries_in_the_next_month(): void
    {
        $soon = $this->staff('1992-06-20', '2023-06-25');
        $later = $this->staff('1992-09-01', '2023-09-01');

        $this->actingAs($this->viewer())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('celebrations.birthdays', fn (Collection $list): bool => $list->contains('id', $soon->id)
                    && ! $list->contains('id', $later->id))
                ->where('celebrations.anniversaries', fn (Collection $list): bool => $list->contains('id', $soon->id)
                    && ! $list->contains('id', $later->id))
            );
    }

    public function test_a_celebration_today_is_marked_as_today(): void
    {
        $this->staff('1992-06-10', '2019-01-01');

        $this->actingAs($this->viewer())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('celebrations.birthdays', fn (Collection $list): bool => $list
                    ->where('is_today', true)
                    ->where('when', 'Today')
                    ->isNotEmpty())
            );
    }

    /**
     * A year is counted from the day they started, so someone hired this year
     * has nothing to mark yet.
     */
    public function test_the_first_year_is_the_earliest_anniversary_shown(): void
    {
        $fresh = $this->staff('1992-01-01', '2026-06-20');

        $this->actingAs($this->viewer())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('celebrations.anniversaries', fn (Collection $list): bool => ! $list->contains('id', $fresh->id))
            );
    }

    public function test_an_anniversary_carries_the_number_of_years(): void
    {
        $person = $this->staff('1992-01-01', '2021-06-20');

        $this->actingAs($this->viewer())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('celebrations.anniversaries', fn (Collection $list): bool => $list
                    ->firstWhere('id', $person->id)['years'] === 5)
            );
    }

    /**
     * Administrators do not appear on the payroll, so they are not celebrated
     * with the staff. They still see everyone else's.
     */
    public function test_administrators_see_the_list_but_are_not_on_it(): void
    {
        $admin = User::factory()->superAdmin()->create([
            'hired_at' => Carbon::parse('2019-06-15'),
        ]);

        $person = $this->staff('1992-06-15', '2021-06-15');

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('celebrations.anniversaries', fn (Collection $list): bool => $list->contains('id', $person->id)
                    && ! $list->contains('id', $admin->id))
            );
    }

    public function test_deactivated_staff_are_left_out(): void
    {
        $gone = $this->staff('1992-06-15', '2021-06-15');
        $gone->update(['is_active' => false]);

        $this->actingAs($this->viewer())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('celebrations.birthdays', fn (Collection $list): bool => ! $list->contains('id', $gone->id))
            );
    }

    /**
     * A birthday a fortnight behind us belongs to next year, not this one, so
     * it must not creep back onto the list.
     */
    public function test_a_date_that_has_passed_is_not_shown(): void
    {
        $passed = $this->staff('1992-05-27', '2021-05-27');

        $this->actingAs($this->viewer())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('celebrations.birthdays', fn (Collection $list): bool => ! $list->contains('id', $passed->id))
            );
    }
}
