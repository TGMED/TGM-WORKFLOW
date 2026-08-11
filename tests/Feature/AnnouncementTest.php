<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function staff(): User
    {
        return User::factory()->create(['location_id' => $this->location->id]);
    }

    public function test_an_administrator_posts_a_notice_and_it_reaches_every_dashboard(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/announcements', [
                'title' => 'Office closed on Monday',
                'body' => "The building is shut for maintenance.\nWork from home.",
                'published_at' => now()->toDateString(),
            ])
            ->assertRedirect();

        $announcement = Announcement::query()->sole();

        $this->assertSame($admin->id, $announcement->user_id);
        $this->assertTrue($announcement->isLive());

        $this->actingAs($this->staff())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('announcements', fn (Collection $list): bool => $list->contains('title', 'Office closed on Monday'))
            );
    }

    public function test_only_administrators_manage_announcements(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->get('/admin/announcements')->assertForbidden();
        $this->actingAs($staff)
            ->post('/admin/announcements', ['title' => 'Mine now', 'body' => 'Hello'])
            ->assertForbidden();
    }

    public function test_drafts_scheduled_and_expired_notices_stay_off_the_dashboard(): void
    {
        Announcement::factory()->draft()->create(['title' => 'Half written']);
        Announcement::factory()->scheduled()->create(['title' => 'Next week']);
        Announcement::factory()->expired()->create(['title' => 'Last month']);
        Announcement::factory()->create(['title' => 'Up now']);

        $this->actingAs($this->staff())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('announcements', fn (Collection $list): bool => $list->pluck('title')->all() === ['Up now'])
            );
    }

    public function test_pinned_notices_come_first(): void
    {
        Announcement::factory()->create([
            'title' => 'Newest',
            'published_at' => now()->subHour(),
        ]);
        Announcement::factory()->pinned()->create([
            'title' => 'Pinned but older',
            'published_at' => now()->subWeek(),
        ]);

        $this->actingAs($this->staff())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('announcements', fn (Collection $list): bool => $list->pluck('title')->all() === ['Pinned but older', 'Newest'])
            );
    }

    public function test_an_edit_can_clear_a_date_that_was_set(): void
    {
        $announcement = Announcement::factory()->create([
            'expires_at' => now()->addWeek(),
        ]);

        $this->actingAs($this->admin())
            ->put("/admin/announcements/{$announcement->id}", [
                'title' => $announcement->title,
                'body' => $announcement->body,
                'published_at' => now()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertNull($announcement->refresh()->expires_at);
    }

    public function test_a_notice_cannot_come_down_before_it_goes_up(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/announcements', [
                'title' => 'Backwards',
                'body' => 'Something',
                'published_at' => now()->toDateString(),
                'expires_at' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('expires_at');
    }

    public function test_the_title_and_body_are_required(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/announcements', [])
            ->assertSessionHasErrors(['title', 'body']);
    }

    public function test_an_announcement_can_be_deleted(): void
    {
        $announcement = Announcement::factory()->create();

        $this->actingAs($this->admin())
            ->delete("/admin/announcements/{$announcement->id}")
            ->assertRedirect();

        $this->assertModelMissing($announcement);
    }

    /**
     * A notice outlives the administrator who wrote it.
     */
    public function test_deleting_the_author_leaves_the_notice_standing(): void
    {
        $admin = $this->admin();
        $announcement = Announcement::factory()->create(['user_id' => $admin->id]);

        $admin->delete();

        $this->assertNull($announcement->refresh()->user_id);

        $this->actingAs($this->staff())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('announcements', fn (Collection $list): bool => $list->count() === 1)
            );
    }

    public function test_the_admin_page_lists_every_notice_with_where_it_stands(): void
    {
        Announcement::factory()->draft()->create();
        Announcement::factory()->expired()->create();
        Announcement::factory()->create();

        $this->actingAs($this->admin())
            ->get('/admin/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/Announcements')
                ->where('announcements', fn (Collection $list): bool => $list->count() === 3
                    && $list->pluck('state')->sort()->values()->all() === ['draft', 'expired', 'live'])
            );
    }
}
