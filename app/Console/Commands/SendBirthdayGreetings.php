<?php

namespace App\Console\Commands;

use App\Models\BirthdayGreeting;
use App\Models\User;
use App\Notifications\BirthdayGreeting as Greeting;
use App\Services\Celebrations;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Greets whoever has a birthday today. Run daily; safe to run again, since
 * the greeting log is written before the notification goes out and its unique
 * key is what stops a second send.
 */
class SendBirthdayGreetings extends Command
{
    protected $signature = 'birthdays:greet
                            {--date= : The day to greet, for backfilling a run that was missed}';

    protected $description = 'Send a birthday greeting to everyone celebrating today';

    public function handle(Celebrations $celebrations): int
    {
        $date = $this->option('date') === null
            ? Carbon::now()
            : Carbon::parse((string) $this->option('date'));

        $celebrating = $celebrations->birthdaysOn($date);

        if ($celebrating->isEmpty()) {
            $this->info('Nobody is celebrating on '.$date->toFormattedDateString().'.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($celebrating as $user) {
            if (! $this->claim($user, $date->year)) {
                continue;
            }

            $user->notify(new Greeting);
            $sent++;
        }

        $this->info("Greeted {$sent} of {$celebrating->count()} celebrating today.");

        return self::SUCCESS;
    }

    /**
     * Write the log row first and treat a clash as somebody else's win. Two
     * runs overlapping is the only way this happens, and the loser staying
     * quiet is exactly what should happen.
     */
    protected function claim(User $user, int $year): bool
    {
        try {
            BirthdayGreeting::query()->create([
                'user_id' => $user->id,
                'year' => $year,
                'sent_at' => Carbon::now(),
            ]);
        } catch (QueryException) {
            return false;
        }

        return true;
    }
}
