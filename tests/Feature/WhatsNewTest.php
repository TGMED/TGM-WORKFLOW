<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use App\Support\WhatsNew;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsNewTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create([
            'location_id' => Location::factory()->create()->id,
        ]);
    }

    public function test_someone_who_has_not_read_the_notes_is_shown_them(): void
    {
        $this->actingAs($this->staff())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('whats_new.version', WhatsNew::version())
                ->has('whats_new.features'));
    }

    public function test_dismissing_the_notes_puts_them_away_for_good(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post('/whats-new/seen')->assertSessionHasNoErrors();

        $this->assertSame(WhatsNew::version(), $staff->fresh()->whats_new_seen);

        $this->actingAs($staff->fresh())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('whats_new', null));
    }

    public function test_a_new_release_brings_the_notes_back(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post('/whats-new/seen');

        config(['whats_new.version' => '2026.09']);

        $this->actingAs($staff->fresh())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('whats_new.version', '2026.09'));
    }

    public function test_the_notes_are_not_offered_to_a_visitor_who_is_not_signed_in(): void
    {
        $this->get('/login')
            ->assertInertia(fn ($page) => $page->where('whats_new', null));
    }
}
