<?php

namespace App\Console\Commands;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Location;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * Re-reads every recorded arrival against its own site's start time and grace
 * window and settles the status again.
 *
 * Status is normally written once, at punch time, and left alone. That leaves
 * days recorded before the grace window became a status of its own stuck on
 * the old two-way verdict, which is why an arrival well past the cutoff can
 * still be sitting there marked on time. This is the one-off repair.
 */
class ReclassifyAttendance extends Command
{
    protected $signature = 'attendance:reclassify {--dry-run : Report what would change without writing}';

    protected $description = 'Recompute on time / within grace / late for recorded attendance';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;
        $skipped = 0;

        Attendance::query()
            ->whereNotNull('clocked_in_at')
            ->with('location')
            ->chunkById(500, function ($rows) use ($dry, &$changed, &$skipped): void {
                foreach ($rows as $attendance) {
                    $location = $attendance->location;

                    // Nothing to measure against once the site is gone.
                    if ($location === null) {
                        $skipped++;

                        continue;
                    }

                    $localIn = $attendance->clocked_in_at->copy()->setTimezone($location->timezone);
                    [$status, $lateMinutes] = $this->verdict($location, $localIn);

                    if ($status === $attendance->status && $lateMinutes === $attendance->late_minutes) {
                        continue;
                    }

                    $this->line(sprintf(
                        '  %s  %-22s %s → %s',
                        $attendance->work_date->toDateString(),
                        $localIn->format('H:i'),
                        $attendance->status->label(),
                        $status->label(),
                    ));

                    $changed++;

                    if (! $dry) {
                        $attendance->forceFill([
                            'status' => $status,
                            'late_minutes' => $lateMinutes,
                        ])->save();
                    }
                }
            });

        if ($skipped > 0) {
            $this->warn("{$skipped} record(s) skipped: no site to measure against.");
        }

        $this->info($dry
            ? "{$changed} record(s) would be reclassified."
            : "{$changed} record(s) reclassified.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: AttendanceStatus, 1: int}
     */
    private function verdict(Location $location, CarbonInterface $localIn): array
    {
        $start = $location->startOfWorkFor($localIn);
        $cutoff = $location->latenessCutoffFor($localIn);

        if ($localIn->lessThanOrEqualTo($start)) {
            return [AttendanceStatus::OnTime, 0];
        }

        if ($localIn->lessThanOrEqualTo($cutoff)) {
            return [AttendanceStatus::Grace, 0];
        }

        return [AttendanceStatus::Late, (int) $start->diffInMinutes($localIn)];
    }
}
