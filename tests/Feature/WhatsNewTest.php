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
                ->has('whats_new.features')
                ->has('whats_new.fixes'));
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

        // Any version that is not the one they just dismissed, derived rather
        // than written down so shipping a release does not break this test.
        $next = WhatsNew::version().'-next';

        config(['whats_new.releases' => [
            ['version' => $next, 'date' => now()->toDateString(), 'features' => [], 'fixes' => []],
            ...config('whats_new.releases'),
        ]]);

        $this->actingAs($staff->fresh())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('whats_new.version', $next));
    }

    public function test_the_notes_are_not_offered_to_a_visitor_who_is_not_signed_in(): void
    {
        $this->get('/login')
            ->assertInertia(fn ($page) => $page->where('whats_new', null));
    }

    public function test_the_popup_carries_only_the_latest_release(): void
    {
        config(['whats_new.releases' => [
            ['version' => 'new', 'date' => '2026-09-21', 'features' => [['title' => 'Latest thing', 'description' => '']], 'fixes' => [['title' => 'Mended thing', 'description' => '']]],
            ['version' => 'old', 'date' => '2026-08-20', 'features' => [['title' => 'Older thing', 'description' => '']], 'fixes' => []],
        ]]);

        $this->actingAs($this->staff())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('whats_new.version', 'new')
                ->has('whats_new.features', 1)
                ->where('whats_new.features.0.title', 'Latest thing')
                ->where('whats_new.fixes.0.title', 'Mended thing'));
    }

    public function test_every_release_is_kept_on_its_own_page(): void
    {
        $this->actingAs($this->staff())
            ->get('/whats-new')
            ->assertInertia(fn ($page) => $page
                ->component('WhatsNew')
                ->has('releases', count(config('whats_new.releases')))
                ->where('releases.0.version', WhatsNew::version()));
    }

    public function test_reading_the_page_puts_the_popup_away(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->get('/whats-new')
            ->assertInertia(fn ($page) => $page->where('whats_new', null));

        $this->assertSame(WhatsNew::version(), $staff->fresh()->whats_new_seen);
    }

    public function test_an_admin_can_read_the_page_too(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/whats-new')
            ->assertSuccessful();
    }
}
