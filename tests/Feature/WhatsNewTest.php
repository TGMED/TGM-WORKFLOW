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
            ['version' => $next, 'date' => now()->toDateString(), 'features' => [['title' => 'Next thing', 'description' => '']], 'fixes' => []],
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

    public function test_a_note_with_an_audience_reaches_only_the_people_in_it(): void
    {
        config(['whats_new.releases' => [
            ['version' => 'new', 'date' => '2026-09-21', 'features' => [
                ['title' => 'For everybody', 'description' => ''],
                ['title' => 'For staff', 'description' => '', 'audience' => ['staff']],
                ['title' => 'For approvers', 'description' => '', 'audience' => ['approvers']],
                ['title' => 'For payroll', 'description' => '', 'audience' => ['payroll.manage']],
            ], 'fixes' => [
                ['title' => 'For admins', 'description' => '', 'audience' => ['admins']],
            ]],
        ]]);

        $this->actingAs($this->staff())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('whats_new.features', 2)
                ->where('whats_new.features.0.title', 'For everybody')
                ->where('whats_new.features.1.title', 'For staff')
                ->where('whats_new.features.1', fn ($note) => ! isset($note['audience']))
                ->has('whats_new.fixes', 0));

        $approver = User::factory()->approver()->create([
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->actingAs($approver)
            ->get('/whats-new')
            ->assertInertia(fn ($page) => $page
                ->has('releases.0.features', 3)
                ->where('releases.0.features.2.title', 'For approvers'));

        // Super admins hold every permission, but they do not clock in.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/whats-new')
            ->assertInertia(fn ($page) => $page
                ->where('releases.0.features', fn ($features) => collect($features)->pluck('title')->all() === ['For everybody', 'For approvers', 'For payroll'])
                ->where('releases.0.fixes.0.title', 'For admins'));
    }

    public function test_a_release_with_nothing_for_somebody_is_not_shown_to_them(): void
    {
        config(['whats_new.releases' => [
            ['version' => 'new', 'date' => '2026-09-21', 'features' => [
                ['title' => 'For payroll', 'description' => '', 'audience' => ['payroll.manage']],
            ], 'fixes' => []],
            ['version' => 'old', 'date' => '2026-08-20', 'features' => [
                ['title' => 'For everybody', 'description' => ''],
            ], 'fixes' => []],
        ]]);

        $staff = $this->staff();

        $this->actingAs($staff)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('whats_new', null));

        $this->actingAs($staff)
            ->get('/whats-new')
            ->assertInertia(fn ($page) => $page
                ->has('releases', 1)
                ->where('releases.0.version', 'old'));
    }

    public function test_every_audience_in_the_notes_names_something_real(): void
    {
        $user = $this->staff();

        foreach (config('whats_new.releases') as $release) {
            foreach ([...$release['features'] ?? [], ...$release['fixes'] ?? []] as $note) {
                // An unknown entry throws rather than returning false.
                WhatsNew::reaches($user, $note['audience'] ?? []);
            }
        }

        $this->addToAssertionCount(1);
    }
}
