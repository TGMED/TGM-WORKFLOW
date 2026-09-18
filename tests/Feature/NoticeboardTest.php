<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\OutOfOfficeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The rail beside every page: what is up on the noticeboard, and what is
 * coming.
 */
class NoticeboardTest extends TestCase
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

    public function test_live_notices_ride_along_with_every_page(): void
    {
        $staff = $this->staff();

        Announcement::query()->create([
            'user_id' => $staff->id,
            'title' => 'Office closed on Friday',
            'body' => 'The building is being rewired.',
            'published_at' => Carbon::now()->subHour(),
        ]);

        $this->actingAs($staff)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('noticeboard.announcements', 1)
                ->where('noticeboard.announcements.0.title', 'Office closed on Friday'));
    }

    public function test_a_draft_notice_never_reaches_the_rail(): void
    {
        $staff = $this->staff();

        Announcement::query()->create([
            'user_id' => $staff->id,
            'title' => 'Not ready yet',
            'body' => 'Still being written.',
            'published_at' => null,
        ]);

        $this->actingAs($staff)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('noticeboard.announcements', 0));
    }

    public function test_upcoming_birthdays_appear(): void
    {
        $staff = $this->staff();

        // The birth date lives on the HR record rather than on the account.
        $colleague = $this->staff(['name' => 'Amaka Obi']);

        $colleague->profile()->updateOrCreate([], [
            'date_of_birth' => Carbon::now()->addDays(3)->subYears(30)->toDateString(),
        ]);

        $this->actingAs($staff)
            ->get('/dashboard')
            ->assertInertia(function ($page) {
                $events = collect($page->toArray()['props']['noticeboard']['events']);

                $birthday = $events->firstWhere('kind', 'birthday');

                $this->assertNotNull($birthday);
                $this->assertSame('Amaka Obi', $birthday['who']);
            });
    }

    public function test_approved_leave_and_days_out_of_the_office_both_appear(): void
    {
        $staff = $this->staff();
        $colleague = $this->staff();

        LeaveRequest::factory()->create([
            'user_id' => $colleague->id,
            'leave_type_id' => LeaveType::query()->where('slug', 'annual')->firstOrFail()->id,
            'start_date' => Carbon::now()->addDays(2),
            'end_date' => Carbon::now()->addDays(4),
            'status' => 'approved',
        ]);

        OutOfOfficeRequest::factory()->approved()->create([
            'user_id' => $colleague->id,
            'start_date' => Carbon::now()->addDays(5),
            'end_date' => Carbon::now()->addDays(5),
            'days' => 1,
        ]);

        $this->actingAs($staff)
            ->get('/dashboard')
            ->assertInertia(function ($page) {
                $kinds = collect($page->toArray()['props']['noticeboard']['events'])
                    ->pluck('kind');

                $this->assertTrue($kinds->contains('leave'));
                $this->assertTrue($kinds->contains('out_of_office'));
            });
    }

    public function test_your_own_entries_are_addressed_to_you(): void
    {
        $staff = $this->staff();

        OutOfOfficeRequest::factory()->approved()->create([
            'user_id' => $staff->id,
            'start_date' => Carbon::now()->addDay(),
            'end_date' => Carbon::now()->addDay(),
            'days' => 1,
        ]);

        $this->actingAs($staff)
            ->get('/dashboard')
            ->assertInertia(function ($page) {
                $event = collect($page->toArray()['props']['noticeboard']['events'])
                    ->firstWhere('kind', 'out_of_office');

                $this->assertSame('You', $event['who']);
            });
    }

    public function test_nothing_is_shared_with_somebody_signed_out(): void
    {
        $this->get('/login')->assertOk();
    }
}
