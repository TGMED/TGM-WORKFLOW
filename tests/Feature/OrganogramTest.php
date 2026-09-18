<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who reports to whom, drawn from the reporting line.
 */
class OrganogramTest extends TestCase
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

    public function test_everybody_can_read_the_chart(): void
    {
        $head = $this->staff();
        $manager = $this->staff(['manager_id' => $head->id]);
        $junior = $this->staff(['manager_id' => $manager->id]);

        $this->actingAs($junior)
            ->get('/organogram')
            ->assertOk()
            ->assertInertia(function ($page) use ($head, $manager, $junior) {
                $props = $page->toArray()['props'];

                $this->assertSame(3, $props['totals']['people']);
                $this->assertSame([$head->id], $props['roots']);

                $nodes = collect($props['nodes'])->keyBy('id');

                $this->assertSame($manager->id, $nodes[$junior->id]['manager_id']);
                $this->assertSame(1, $nodes[$head->id]['reports']);
                // Everyone below, however many rungs down.
                $this->assertSame(2, $nodes[$head->id]['below']);
                $this->assertTrue($nodes[$junior->id]['is_you']);
            });
    }

    public function test_somebody_pointing_at_a_departed_manager_sits_at_the_top(): void
    {
        $manager = $this->staff();
        $orphan = $this->staff(['manager_id' => $manager->id]);

        // Deactivated rather than deleted, which is what an exit does.
        $manager->update(['is_active' => false]);

        $this->actingAs($orphan)
            ->get('/organogram')
            ->assertInertia(fn ($page) => $page->where('roots', [$orphan->id]));
    }

    public function test_a_loop_in_the_data_does_not_hang_the_page(): void
    {
        $one = $this->staff();
        $two = $this->staff(['manager_id' => $one->id]);
        $one->forceFill(['manager_id' => $two->id])->save();

        $this->actingAs($this->staff())
            ->get('/organogram')
            ->assertOk();
    }

    public function test_people_with_no_manager_are_counted_in_plain_sight(): void
    {
        $this->staff();
        $this->staff();

        $this->actingAs($this->staff())
            ->get('/organogram')
            ->assertInertia(fn ($page) => $page->where('totals.unplaced', 3));
    }
}
