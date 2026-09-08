<?php

namespace Tests\Feature;

use App\Enums\NotificationTopic;
use App\Models\Location;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Models\WorkAnniversaryGreeting;
use App\Notifications\WorkAnniversary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkAnniversaryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Somebody who started on the date given.
     */
    private function hiredOn(string $date): User
    {
        return User::factory()->create([
            'location_id' => Location::factory()->create()->id,
            'hired_at' => $date,
        ]);
    }

    public function test_the_person_marking_one_is_written_to(): void
    {
        Carbon::setTestNow('2026-06-15 07:05:00');

        Notification::fake();

        $celebrating = $this->hiredOn('2020-06-15');
        $everyoneElse = $this->hiredOn('2020-09-02');

        $this->artisan('anniversaries:greet')->assertSuccessful();

        Notification::assertSentTo($celebrating, WorkAnniversary::class);
        // How long someone has been here is theirs to mention.
        Notification::assertNotSentTo($everyoneElse, WorkAnniversary::class);
    }

    public function test_the_note_counts_the_years_served(): void
    {
        Carbon::setTestNow('2026-06-15 07:05:00');

        Notification::fake();

        $celebrating = $this->hiredOn('2020-06-15');

        $this->artisan('anniversaries:greet')->assertSuccessful();

        Notification::assertSentTo($celebrating, WorkAnniversary::class, function (WorkAnniversary $note) use ($celebrating): bool {
            $mail = $note->toMail($celebrating);

            return $mail->subject === '6 years with us today';
        });

        $this->assertSame(6, WorkAnniversaryGreeting::query()->sole()->years_of_service);
    }

    public function test_the_first_anniversary_reads_as_one_year(): void
    {
        Carbon::setTestNow('2026-06-15 07:05:00');

        Notification::fake();

        $celebrating = $this->hiredOn('2025-06-15');

        $this->artisan('anniversaries:greet')->assertSuccessful();

        Notification::assertSentTo($celebrating, WorkAnniversary::class, function (WorkAnniversary $note) use ($celebrating): bool {
            return $note->toMail($celebrating)->subject === 'One year with us today';
        });
    }

    public function test_somebody_hired_today_has_no_anniversary_yet(): void
    {
        Carbon::setTestNow('2026-06-15 07:05:00');

        Notification::fake();

        $this->hiredOn('2026-06-15');

        $this->artisan('anniversaries:greet')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_second_run_on_the_same_day_stays_quiet(): void
    {
        Carbon::setTestNow('2026-06-15 07:05:00');

        $celebrating = $this->hiredOn('2020-06-15');

        $this->artisan('anniversaries:greet')->assertSuccessful();

        Notification::fake();

        $this->artisan('anniversaries:greet')->assertSuccessful();

        Notification::assertNotSentTo($celebrating, WorkAnniversary::class);
        $this->assertSame(1, WorkAnniversaryGreeting::query()->where('user_id', $celebrating->id)->count());
    }

    public function test_the_same_person_is_written_to_again_the_next_year(): void
    {
        Carbon::setTestNow('2026-06-15 07:05:00');

        $celebrating = $this->hiredOn('2020-06-15');

        $this->artisan('anniversaries:greet');

        Carbon::setTestNow('2027-06-15 07:05:00');

        Notification::fake();

        $this->artisan('anniversaries:greet')->assertSuccessful();

        Notification::assertSentTo($celebrating, WorkAnniversary::class);
    }

    public function test_someone_who_has_left_is_not_written_to(): void
    {
        Carbon::setTestNow('2026-06-15 07:05:00');

        Notification::fake();

        $gone = $this->hiredOn('2020-06-15');
        $gone->forceFill(['is_active' => false])->save();

        $this->artisan('anniversaries:greet')->assertSuccessful();

        Notification::assertNotSentTo($gone, WorkAnniversary::class);
    }

    public function test_a_record_with_no_hire_date_is_skipped(): void
    {
        Carbon::setTestNow('2026-06-15 07:05:00');

        Notification::fake();

        $this->hiredOn('2020-06-15')->forceFill(['hired_at' => null])->save();

        $this->artisan('anniversaries:greet')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_leap_day_start_is_marked_on_the_28th_in_a_common_year(): void
    {
        Carbon::setTestNow('2027-02-28 07:05:00');

        Notification::fake();

        $celebrating = $this->hiredOn('2020-02-29');

        $this->artisan('anniversaries:greet')->assertSuccessful();

        Notification::assertSentTo($celebrating, WorkAnniversary::class);
    }

    public function test_a_missed_run_can_be_caught_up_with_a_date(): void
    {
        Carbon::setTestNow('2026-06-17 09:00:00');

        Notification::fake();

        $celebrating = $this->hiredOn('2020-06-15');

        $this->artisan('anniversaries:greet', ['--date' => '2026-06-15'])->assertSuccessful();

        Notification::assertSentTo($celebrating, WorkAnniversary::class);
    }

    public function test_somebody_who_switched_the_topic_off_gets_no_email(): void
    {
        Carbon::setTestNow('2026-06-15 07:05:00');

        $celebrating = $this->hiredOn('2020-06-15');

        NotificationSetting::query()->create([
            'user_id' => $celebrating->id,
            'topic' => NotificationTopic::Anniversary->value,
            'email' => false,
            'push' => false,
        ]);

        $this->assertSame([], (new WorkAnniversary(6))->via($celebrating->fresh()));
    }

    public function test_the_topic_is_on_for_anyone_who_never_touched_it(): void
    {
        $celebrating = $this->hiredOn('2020-06-15');

        $this->assertContains('mail', (new WorkAnniversary(6))->via($celebrating));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
