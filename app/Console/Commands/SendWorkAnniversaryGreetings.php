<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WorkAnniversaryGreeting;
use App\Notifications\WorkAnniversary;
use App\Services\Celebrations;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Marks the anniversary of whoever joined on this day in an earlier year. Run
 * daily; safe to run again, since the log row is written before the
 * notification goes out and its unique key is what stops a second send.
 */
class SendWorkAnniversaryGreetings extends Command
{
    protected $signature = 'anniversaries:greet
                            {--date= : The day to mark, for backfilling a run that was missed}';

    protected $description = 'Send a work anniversary note to everyone marking one today';

    public function handle(Celebrations $celebrations): int
    {
        $date = $this->option('date') === null
            ? Carbon::now()
            : Carbon::parse((string) $this->option('date'));

        $celebrating = $celebrations->anniversariesOn($date);

        if ($celebrating->isEmpty()) {
            $this->info('Nobody is marking an anniversary on '.$date->toFormattedDateString().'.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($celebrating as $user) {
            $years = $celebrations->yearsServedOn($user, $date);

            if (! $this->claim($user, $date->year, $years)) {
                continue;
            }

            $user->notify(new WorkAnniversary($years));
            $sent++;
        }

        $this->info("Marked {$sent} of {$celebrating->count()} anniversaries today.");

        return self::SUCCESS;
    }

    /**
     * Write the log row first and treat a clash as somebody else's win. Two
     * runs overlapping is the only way this happens, and the loser staying
     * quiet is exactly what should happen.
     */
    protected function claim(User $user, int $year, int $years): bool
    {
        try {
            WorkAnniversaryGreeting::query()->create([
                'user_id' => $user->id,
                'year' => $year,
                'years_of_service' => $years,
                'sent_at' => Carbon::now(),
            ]);
        } catch (QueryException) {
            return false;
        }

        return true;
    }
}
