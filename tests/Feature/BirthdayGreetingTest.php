<?php

namespace Tests\Feature;

use App\Models\BirthdayGreeting;
use App\Models\Location;
use App\Models\User;
use App\Notifications\BirthdayGreeting as Greeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BirthdayGreetingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Somebody born on the day and month given, whatever the year.
     */
    private function bornOn(string $monthDay, int $year = 1990): User
    {
        $user = User::factory()->create([
            'location_id' => Location::factory()->create()->id,
        ]);

        $user->profile->update(['date_of_birth' => "{$year}-{$monthDay}"]);

        return $user;
    }

    public function test_the_person_celebrating_is_greeted(): void
    {
        Carbon::setTestNow('2026-06-15 07:00:00');

        Notification::fake();

        $celebrating = $this->bornOn('06-15');
        $everyoneElse = $this->bornOn('09-02');

        $this->artisan('birthdays:greet')->assertSuccessful();

        Notification::assertSentTo($celebrating, Greeting::class);
        // A birthday is nobody else's business.
        Notification::assertNotSentTo($everyoneElse, Greeting::class);
    }

    public function test_a_second_run_on_the_same_day_stays_quiet(): void
    {
        Carbon::setTestNow('2026-06-15 07:00:00');

        $celebrating = $this->bornOn('06-15');

        $this->artisan('birthdays:greet')->assertSuccessful();

        Notification::fake();

        $this->artisan('birthdays:greet')->assertSuccessful();

        Notification::assertNotSentTo($celebrating, Greeting::class);
        $this->assertSame(1, BirthdayGreeting::query()->where('user_id', $celebrating->id)->count());
    }

    public function test_the_same_person_is_greeted_again_the_next_year(): void
    {
        Carbon::setTestNow('2026-06-15 07:00:00');

        $celebrating = $this->bornOn('06-15');

        $this->artisan('birthdays:greet');

        Carbon::setTestNow('2027-06-15 07:00:00');

        Notification::fake();

        $this->artisan('birthdays:greet')->assertSuccessful();

        Notification::assertSentTo($celebrating, Greeting::class);
    }

    public function test_someone_who_has_left_is_not_greeted(): void
    {
        Carbon::setTestNow('2026-06-15 07:00:00');

        Notification::fake();

        $gone = $this->bornOn('06-15');
        $gone->forceFill(['is_active' => false])->save();

        $this->artisan('birthdays:greet')->assertSuccessful();

        Notification::assertNotSentTo($gone, Greeting::class);
    }

    public function test_a_profile_with_no_birthday_is_skipped(): void
    {
        Carbon::setTestNow('2026-06-15 07:00:00');

        Notification::fake();

        $user = User::factory()->create([
            'location_id' => Location::factory()->create()->id,
        ]);
        $user->profile->update(['date_of_birth' => null]);

        $this->artisan('birthdays:greet')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_leap_day_birthday_is_greeted_on_the_28th_in_a_common_year(): void
    {
        Carbon::setTestNow('2027-02-28 07:00:00');

        Notification::fake();

        $celebrating = $this->bornOn('02-29', 1992);

        $this->artisan('birthdays:greet')->assertSuccessful();

        Notification::assertSentTo($celebrating, Greeting::class);
    }

    public function test_a_missed_run_can_be_caught_up_with_a_date(): void
    {
        Carbon::setTestNow('2026-06-17 09:00:00');

        Notification::fake();

        $celebrating = $this->bornOn('06-15');

        $this->artisan('birthdays:greet', ['--date' => '2026-06-15'])->assertSuccessful();

        Notification::assertSentTo($celebrating, Greeting::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
