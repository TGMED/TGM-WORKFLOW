<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Location;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use App\Services\AnnouncementPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function staff(): User
    {
        return User::factory()->create([
            'location_id' => Location::factory()->create()->id,
        ]);
    }

    public function test_publishing_a_notice_writes_to_everyone_else(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $first = $this->staff();
        $second = $this->staff();

        $this->actingAs($admin)
            ->post('/admin/announcements', [
                'title' => 'Office closed on Friday',
                'body' => 'The building is being rewired.',
                'published_at' => Carbon::now()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo([$first, $second], AnnouncementPublished::class);
        // The author already knows: they wrote it.
        Notification::assertNotSentTo($admin, AnnouncementPublished::class);

        $this->assertNotNull(Announcement::query()->firstOrFail()->notified_at);
    }

    public function test_a_draft_tells_nobody(): void
    {
        Notification::fake();

        $staff = $this->staff();

        $this->actingAs($this->admin())
            ->post('/admin/announcements', [
                'title' => 'Still thinking about this one',
                'body' => 'Not ready to go out yet.',
                'published_at' => null,
            ])
            ->assertSessionHasNoErrors();

        Notification::assertNotSentTo($staff, AnnouncementPublished::class);

        $announcement = Announcement::query()->firstOrFail();

        $this->assertSame('draft', $announcement->state());
        $this->assertNull($announcement->notified_at);
    }

    public function test_a_notice_dated_for_later_waits_for_its_day(): void
    {
        Notification::fake();

        $staff = $this->staff();

        $this->actingAs($this->admin())
            ->post('/admin/announcements', [
                'title' => 'Christmas shutdown',
                'body' => 'The dates for the shutdown are below.',
                'published_at' => Carbon::now()->addWeek()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        Notification::assertNotSentTo($staff, AnnouncementPublished::class);
        $this->assertSame('scheduled', Announcement::query()->firstOrFail()->state());
    }

    public function test_the_sweep_sends_a_scheduled_notice_once_its_day_comes(): void
    {
        $staff = $this->staff();

        $announcement = Announcement::factory()->scheduled()->create([
            'user_id' => $this->admin()->id,
        ]);

        Notification::fake();

        // Nothing to do while it is still dated for later.
        $this->artisan('announcements:send')->assertSuccessful();
        Notification::assertNotSentTo($staff, AnnouncementPublished::class);

        Carbon::setTestNow(Carbon::now()->addWeeks(2));

        $this->artisan('announcements:send')->assertSuccessful();

        Notification::assertSentTo($staff, AnnouncementPublished::class);
        $this->assertNotNull($announcement->fresh()->notified_at);
    }

    public function test_a_deactivated_person_is_left_out(): void
    {
        Notification::fake();

        $gone = User::factory()->deactivated()->create();

        $this->actingAs($this->admin())->post('/admin/announcements', [
            'title' => 'Everyone still here',
            'body' => 'Only current staff hear this.',
            'published_at' => Carbon::now()->toDateString(),
        ]);

        Notification::assertNotSentTo($gone, AnnouncementPublished::class);
    }

    public function test_republishing_does_not_send_the_notice_twice(): void
    {
        $staff = $this->staff();
        $announcement = Announcement::factory()->create(['user_id' => $this->admin()->id]);

        Notification::fake();

        $sent = app(AnnouncementPublisher::class)->announce($announcement);

        $this->assertSame(0, $sent);
        Notification::assertNotSentTo($staff, AnnouncementPublished::class);
    }

    public function test_pulling_a_notice_back_takes_it_off_the_dashboard(): void
    {
        $announcement = Announcement::factory()->create(['user_id' => $this->admin()->id]);

        $this->actingAs($this->admin())
            ->put("/admin/announcements/{$announcement->id}", [
                'title' => $announcement->title,
                'body' => $announcement->body,
                'published_at' => null,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($announcement->fresh()->published_at);
        // It went out once, and that is a fact about the past.
        $this->assertNotNull($announcement->fresh()->notified_at);
    }

    public function test_a_notice_cannot_come_down_before_it_goes_up(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/announcements', [
                'title' => 'Back to front',
                'body' => 'The dates on this are the wrong way round.',
                'published_at' => Carbon::now()->addWeek()->toDateString(),
                'expires_at' => Carbon::now()->toDateString(),
            ])
            ->assertSessionHasErrors('expires_at');
    }

    public function test_live_notices_reach_the_noticeboard_pinned_first(): void
    {
        $admin = $this->admin();

        Announcement::factory()->create(['user_id' => $admin->id, 'title' => 'Older news']);
        Announcement::factory()->pinned()->create(['user_id' => $admin->id, 'title' => 'Read this first']);
        Announcement::factory()->draft()->create(['user_id' => $admin->id, 'title' => 'Unfinished']);
        Announcement::factory()->expired()->create(['user_id' => $admin->id, 'title' => 'Last month']);

        $this->actingAs($this->staff())
            ->get('/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('announcements.data', 2)
                ->where('announcements.data.0.title', 'Read this first'));
    }

    /**
     * Notices used to be a panel on the dashboard. They have a page of their
     * own now, and the dashboard is for numbers.
     */
    public function test_the_dashboard_no_longer_carries_notices(): void
    {
        Announcement::factory()->create(['user_id' => $this->admin()->id]);

        $this->actingAs($this->staff())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('announcements'));
    }

    public function test_staff_cannot_write_notices(): void
    {
        $this->actingAs($this->staff())
            ->post('/admin/announcements', [
                'title' => 'From me, apparently',
                'body' => 'This should not be allowed.',
                'published_at' => Carbon::now()->toDateString(),
            ])
            ->assertForbidden();
    }

    public function test_a_notice_needs_a_heading_and_a_body(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/announcements', ['title' => '', 'body' => ''])
            ->assertSessionHasErrors(['title', 'body']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
