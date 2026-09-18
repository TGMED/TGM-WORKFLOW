<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which walkthroughs somebody has been shown, kept on their record so a tour
 * seen on one device does not reappear on another.
 */
class GuidedTourTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create([
            'location_id' => Location::factory()->create()->id,
        ]);
    }

    public function test_a_tour_is_marked_as_seen(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post('/tours', ['tour' => 'dashboard'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['dashboard'], $staff->refresh()->tours_seen);
    }

    public function test_the_same_tour_is_not_recorded_twice(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post('/tours', ['tour' => 'dashboard']);
        $this->actingAs($staff)->post('/tours', ['tour' => 'dashboard']);
        $this->actingAs($staff)->post('/tours', ['tour' => 'leave']);

        $this->assertSame(['dashboard', 'leave'], $staff->refresh()->tours_seen);
    }

    public function test_a_made_up_name_is_refused(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post('/tours', ['tour' => 'Robert"; drop table users'])
            ->assertSessionHasErrors('tour');

        $this->assertNull($staff->refresh()->tours_seen);
    }

    public function test_what_has_been_seen_rides_along_with_every_page(): void
    {
        $staff = $this->staff();

        $staff->forceFill(['tours_seen' => ['dashboard']])->save();

        $this->actingAs($staff)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tours_seen', ['dashboard']));
    }

    public function test_they_can_all_be_forgotten_so_the_tours_run_again(): void
    {
        $staff = $this->staff();

        $staff->forceFill(['tours_seen' => ['dashboard', 'leave']])->save();

        $this->actingAs($staff)->delete('/tours');

        $this->assertSame([], $staff->refresh()->tours_seen);
    }

    public function test_somebody_signed_out_cannot_record_one(): void
    {
        $this->post('/tours', ['tour' => 'dashboard'])->assertRedirect('/login');
    }
}
