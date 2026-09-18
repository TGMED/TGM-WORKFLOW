<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\LatenessRequest;
use App\Models\Location;
use App\Models\RequestSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LatenessRequestTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();

        // Lateness is raised ahead of the morning, so every one of these posts
        // has to happen before filing closes. The site resumes at 9am Lagos
        // and the default deadline is an hour before that, which puts these
        // tests at seven o'clock with an hour in hand.
        Carbon::setTestNow(Carbon::parse('07:00', 'Africa/Lagos'));
    }

    private function staff(): User
    {
        return User::factory()->create(['location_id' => $this->location->id]);
    }

    private function lateDay(User $staff, Carbon $date, int $minutes = 25): Attendance
    {
        return Attendance::query()->create([
            'user_id' => $staff->id,
            'location_id' => $this->location->id,
            'work_date' => $date,
            'clocked_in_at' => $date->copy()->setTime(9, $minutes),
            'status' => AttendanceStatus::Late,
            'late_minutes' => $minutes,
        ]);
    }

    public function test_staff_can_explain_todays_late_arrival(): void
    {
        $staff = $this->staff();
        $today = Carbon::now()->startOfDay();
        $attendance = $this->lateDay($staff, $today, 42);

        $this->actingAs($staff)
            ->post('/lateness', [
                'work_date' => $today->toDateString(),
                'reason' => 'Third Mainland Bridge was shut for repairs.',
            ])
            ->assertSessionHasNoErrors();

        $late = LatenessRequest::query()->firstOrFail();

        $this->assertSame($staff->id, $late->user_id);
        $this->assertSame($attendance->id, $late->attendance_id);
        $this->assertSame(RequestStatus::Pending, $late->status);
    }

    public function test_the_minutes_come_from_the_clock_in_not_the_form(): void
    {
        $staff = $this->staff();
        $today = Carbon::now()->startOfDay();
        $this->lateDay($staff, $today, 42);

        $this->actingAs($staff)->post('/lateness', [
            'work_date' => $today->toDateString(),
            'reason' => 'Traffic on the expressway held everyone up.',
            'minutes_late' => 2,
        ]);

        $this->assertSame(42, LatenessRequest::query()->firstOrFail()->minutes_late);
    }

    public function test_a_day_cannot_be_explained_twice(): void
    {
        $staff = $this->staff();
        $today = Carbon::now()->startOfDay();

        LatenessRequest::factory()->create([
            'user_id' => $staff->id,
            'work_date' => $today,
        ]);

        $this->actingAs($staff)
            ->post('/lateness', [
                'work_date' => $today->toDateString(),
                'reason' => 'Another go at the same morning entirely.',
            ])
            ->assertSessionHasErrors('work_date');

        $this->assertSame(1, LatenessRequest::query()->count());
    }

    public function test_a_future_day_cannot_be_explained(): void
    {
        $this->actingAs($this->staff())
            ->post('/lateness', [
                'work_date' => Carbon::now()->addDays(2)->toDateString(),
                'reason' => 'I expect to be late on Thursday morning.',
            ])
            ->assertSessionHasErrors('work_date');
    }

    public function test_a_reason_is_required(): void
    {
        $this->actingAs($this->staff())
            ->post('/lateness', [
                'work_date' => Carbon::now()->toDateString(),
                'reason' => 'traffic',
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_todays_late_arrival_is_offered_but_older_days_are_not(): void
    {
        $staff = $this->staff();
        $this->lateDay($staff, Carbon::now()->startOfDay(), 15);
        // Yesterday is out of reach now, so it is not offered either.
        $this->lateDay($staff, Carbon::now()->subDay()->startOfDay(), 20);

        $this->actingAs($staff)
            ->get('/lateness')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lateness')
                ->where('today', Carbon::now()->toDateString())
                ->where('explained_today', false)
                ->has('unexplained', 1)
                ->where('unexplained.0.late_minutes', 15));
    }

    public function test_a_past_day_can_no_longer_be_explained(): void
    {
        $staff = $this->staff();
        $yesterday = Carbon::now()->subDay()->startOfDay();
        $this->lateDay($staff, $yesterday, 30);

        $this->actingAs($staff)
            ->post('/lateness', [
                'work_date' => $yesterday->toDateString(),
                'reason' => 'The bridge was closed for repairs all morning.',
            ])
            ->assertSessionHasErrors('work_date');

        $this->assertSame(0, LatenessRequest::query()->count());
    }

    public function test_the_page_knows_when_today_is_already_explained(): void
    {
        $staff = $this->staff();

        LatenessRequest::factory()->create([
            'user_id' => $staff->id,
            'work_date' => Carbon::now()->startOfDay(),
        ]);

        $this->actingAs($staff)
            ->get('/lateness')
            ->assertInertia(fn ($page) => $page
                ->where('explained_today', true)
                ->has('unexplained', 0));
    }

    public function test_lateness_cannot_be_raised_once_filing_has_closed(): void
    {
        $staff = $this->staff();
        $today = Carbon::now()->startOfDay();

        // Half past eight at the site: work starts at nine and the deadline
        // sat at eight, so this is half an hour too late to count as notice.
        Carbon::setTestNow(Carbon::parse('08:30', 'Africa/Lagos'));

        $this->actingAs($staff)
            ->post('/lateness', [
                'work_date' => $today->toDateString(),
                'reason' => 'The bridge was closed and traffic is at a standstill.',
            ])
            ->assertSessionHasErrors('work_date');

        $this->assertSame(0, LatenessRequest::query()->count());
    }

    public function test_the_deadline_moves_with_the_setting(): void
    {
        $staff = $this->staff();
        $today = Carbon::now()->startOfDay();

        // Two hours' notice wanted, so seven o'clock is now too late.
        RequestSettings::current()->update(['lateness_cutoff_minutes' => 120]);

        $this->actingAs($staff)
            ->post('/lateness', [
                'work_date' => $today->toDateString(),
                'reason' => 'The bridge was closed and traffic is at a standstill.',
            ])
            ->assertSessionHasErrors('work_date');

        // Back to the hour, and the same post goes through.
        RequestSettings::current()->update(['lateness_cutoff_minutes' => 60]);

        $this->actingAs($staff)
            ->post('/lateness', [
                'work_date' => $today->toDateString(),
                'reason' => 'The bridge was closed and traffic is at a standstill.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, LatenessRequest::query()->count());
    }

    public function test_somebody_with_no_site_is_not_held_to_a_deadline(): void
    {
        // No location means no resumption time to count back from, so the
        // form stays open rather than refusing on a deadline nobody can name.
        $staff = User::factory()->create(['location_id' => null]);

        Carbon::setTestNow(Carbon::parse('11:00', 'Africa/Lagos'));

        $this->actingAs($staff)
            ->post('/lateness', [
                'work_date' => Carbon::now()->startOfDay()->toDateString(),
                'reason' => 'Held up at the clinic with a sick child this morning.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, LatenessRequest::query()->count());
    }

    public function test_the_page_says_when_filing_closes(): void
    {
        $this->actingAs($this->staff())
            ->get('/lateness')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('window.open', true)
                ->where('window.cutoff_minutes', 60)
                ->where('window.closes_at_label', '8:00am'));
    }

    public function test_the_page_knows_when_filing_has_closed(): void
    {
        $staff = $this->staff();

        Carbon::setTestNow(Carbon::parse('08:30', 'Africa/Lagos'));

        $this->actingAs($staff)
            ->get('/lateness')
            ->assertInertia(fn ($page) => $page->where('window.open', false));
    }

    public function test_staff_can_withdraw_a_pending_explanation(): void
    {
        $staff = $this->staff();
        $late = LatenessRequest::factory()->create(['user_id' => $staff->id]);

        $this->actingAs($staff)->delete("/lateness/{$late->id}");

        $this->assertSame(RequestStatus::Cancelled, $late->refresh()->status);
    }

    public function test_staff_cannot_withdraw_someone_elses_explanation(): void
    {
        $late = LatenessRequest::factory()->create();

        $this->actingAs($this->staff())
            ->delete("/lateness/{$late->id}")
            ->assertForbidden();
    }
}
