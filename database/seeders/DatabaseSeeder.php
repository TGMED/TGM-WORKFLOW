<?php

namespace Database\Seeders;

use App\Enums\AttemptResult;
use App\Enums\AttendanceStatus;
use App\Enums\ClockType;
use App\Models\Attendance;
use App\Models\ClockAttempt;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $locations = $this->seedLocations();

        // Admins run the clock rather than punch it, so this one gets no
        // attendance of its own, only a base office for display.
        User::factory()->superAdmin()->create([
            'employee_id' => 'TGM-0001',
            'name' => 'Chidi Okafor',
            'email' => 'admin@tgm.test',
            'password' => 'password',
            'location_id' => $locations['lagos']->id,
        ]);

        // Spread staff across the branches so the per-location reports have shape.
        $staff = collect();

        foreach ([
            ['lagos', 5],
            ['abuja', 3],
            ['kano', 2],
            ['benin', 2],
            ['ibadan', 2],
            ['port-harcourt', 2],
            ['ghana', 2],
            ['uganda', 2],
        ] as [$key, $count]) {
            $staff = $staff->merge(
                User::factory($count)->create(['location_id' => $locations[$key]->id]),
            );
        }

        User::factory(2)->deactivated()->create(['location_id' => $locations['lagos']->id]);

        $this->seedAttendance($staff, $locations);
    }

    /**
     * The eight TGM Education branches.
     *
     * Lives in {@see LocationSeeder} so the branches can be seeded on their own
     * (`db:seed --class=LocationSeeder`) without also creating the demo staff
     * and attendance below, which have no place on a production database.
     *
     * @return array<string, Location>
     */
    protected function seedLocations(): array
    {
        return (new LocationSeeder)->seed();
    }

    /**
     * Six weeks of plausible punches, including a few rejected attempts.
     *
     * @param  Collection<int, User>  $users
     * @param  array<string, Location>  $locations
     */
    protected function seedAttendance(Collection $users, array $locations): void
    {
        $byId = collect($locations)->keyBy('id');

        foreach ($users as $user) {
            /** @var Location|null $location */
            $location = $byId->get($user->location_id);

            if ($location === null) {
                continue;
            }

            $today = Carbon::now()->setTimezone($location->timezone);

            for ($daysAgo = 41; $daysAgo >= 0; $daysAgo--) {
                $date = $today->copy()->subDays($daysAgo);

                if (! $location->isWorkday($date)) {
                    continue;
                }

                // Roughly one absence every couple of weeks.
                if (random_int(1, 100) <= 8) {
                    continue;
                }

                $inMinutes = random_int(1, 100) <= 25
                    ? random_int(12, 75)   // late arrival
                    : random_int(-35, 8);  // on time or early

                $clockIn = $location->startOfWorkFor($date)->addMinutes($inMinutes);

                $isToday = $daysAgo === 0;

                if ($isToday && $clockIn->greaterThan($today)) {
                    continue;
                }

                $late = $inMinutes > $location->grace_minutes;
                $inGrace = ! $late && $inMinutes > 0;
                $distance = random_int(5, (int) ($location->radius_meters * 0.9));

                $clockOut = $isToday && random_int(1, 100) <= 70
                    ? null
                    : $clockIn->copy()->addMinutes(random_int(430, 560));

                if ($clockOut !== null && $clockOut->greaterThan($today)) {
                    $clockOut = null;
                }

                $attendance = Attendance::query()->create([
                    'user_id' => $user->id,
                    'location_id' => $location->id,
                    'work_date' => $date->toDateString(),
                    'clocked_in_at' => $clockIn->copy()->utc(),
                    'clock_in_latitude' => $this->jitter($location->latitude),
                    'clock_in_longitude' => $this->jitter($location->longitude),
                    'clock_in_accuracy' => random_int(8, 60),
                    'clock_in_distance' => $distance,
                    'clocked_out_at' => $clockOut?->copy()->utc(),
                    'clock_out_latitude' => $clockOut ? $this->jitter($location->latitude) : null,
                    'clock_out_longitude' => $clockOut ? $this->jitter($location->longitude) : null,
                    'clock_out_accuracy' => $clockOut ? random_int(8, 60) : null,
                    'clock_out_distance' => $clockOut ? random_int(5, 140) : null,
                    'status' => match (true) {
                        $late => AttendanceStatus::Late,
                        $inGrace => AttendanceStatus::Grace,
                        default => AttendanceStatus::OnTime,
                    },
                    'late_minutes' => $late ? $inMinutes : 0,
                    'worked_minutes' => $clockOut ? (int) $clockIn->diffInMinutes($clockOut) : null,
                ]);

                // Some days start with a rejected attempt from outside the fence.
                if (random_int(1, 100) <= 15) {
                    $rejectedDistance = random_int($location->radius_meters + 60, 4200);
                    $at = $clockIn->copy()->subMinutes(random_int(4, 30));

                    ClockAttempt::query()->create([
                        'user_id' => $user->id,
                        'location_id' => $location->id,
                        'type' => ClockType::In,
                        'result' => AttemptResult::OutOfRange,
                        'message' => "You are {$rejectedDistance}m from {$location->name}.",
                        'latitude' => $this->jitter($location->latitude, 0.03),
                        'longitude' => $this->jitter($location->longitude, 0.03),
                        'accuracy_meters' => random_int(10, 90),
                        'distance_meters' => $rejectedDistance,
                        'ip_address' => '105.112.'.random_int(1, 254).'.'.random_int(1, 254),
                        'user_agent' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/126 Mobile Safari/537.36',
                        'created_at' => $at->utc(),
                        'updated_at' => $at->utc(),
                    ]);
                }

                ClockAttempt::query()->create([
                    'user_id' => $user->id,
                    'location_id' => $location->id,
                    'attendance_id' => $attendance->id,
                    'type' => ClockType::In,
                    'result' => AttemptResult::Success,
                    'message' => 'Clocked in at '.$clockIn->format('g:i A').'.',
                    'latitude' => $attendance->clock_in_latitude,
                    'longitude' => $attendance->clock_in_longitude,
                    'accuracy_meters' => $attendance->clock_in_accuracy,
                    'distance_meters' => $distance,
                    'ip_address' => '105.112.'.random_int(1, 254).'.'.random_int(1, 254),
                    'user_agent' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/126 Mobile Safari/537.36',
                    'created_at' => $clockIn->copy()->utc(),
                    'updated_at' => $clockIn->copy()->utc(),
                ]);
            }
        }
    }

    protected function jitter(float $value, float $spread = 0.0009): float
    {
        return round($value + (random_int(-1000, 1000) / 1000) * $spread, 7);
    }
}
