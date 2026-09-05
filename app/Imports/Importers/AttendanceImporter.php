<?php

namespace App\Imports\Importers;

use App\Enums\AttendanceStatus;
use App\Enums\ImportDuplicates;
use App\Enums\Permission;
use App\Imports\BaseImporter;
use App\Imports\Concerns\ResolvesStaff;
use App\Imports\ImportColumn;
use App\Imports\ImportResult;
use App\Imports\ImportRow;
use App\Models\Attendance;
use App\Models\Location;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Days already worked, brought over from whatever recorded them before —
 * another system, a biometric terminal, a paper register.
 *
 * Status and worked minutes are worked out from the times against the site's
 * own working day, rather than trusted from the file: the point of loading
 * history is that the attendance report reads it the same way it reads a
 * clock-in made here.
 */
class AttendanceImporter extends BaseImporter
{
    use ResolvesStaff;

    public function key(): string
    {
        return 'attendance';
    }

    public function label(): string
    {
        return 'Attendance history';
    }

    public function description(): string
    {
        return 'Days already worked, brought over from a previous system or a clocking terminal.';
    }

    public function permission(): Permission
    {
        return Permission::ViewAttendanceReport;
    }

    /**
     * @return array<int, string>
     */
    public function dependsOn(): array
    {
        return ['staff'];
    }

    public function matchedOn(): string
    {
        return 'the person together with the work date, which the database holds one row of';
    }

    /**
     * @return array<int, string>
     */
    public function notes(): array
    {
        return [
            'Lateness and hours worked are worked out from the times against the site\'s own working day, not taken from the file. A day loaded here reads exactly as one clocked in here would.',
            'Times are read in the site\'s timezone unless the cell carries an offset of its own. A branch in another city is judged against its own clock.',
            'Write times as a date and time together — 2026-03-02 08:47 — or as a bare time, which is taken to be on the work date.',
            'Administrators have no attendance of their own and are rejected: they run the clock rather than punch it.',
            'A day with no clock-in time is still a row: it records that the person was at the site without saying when they arrived.',
        ];
    }

    /**
     * @return array<int, ImportColumn>
     */
    public function columns(): array
    {
        return [
            ...$this->staffColumns(),

            ImportColumn::required('work_date', 'The day being recorded. One row per person per day.', '2026-03-02', format: 'Date'),
            ImportColumn::make('location', 'The site the day was worked at, by name or ID. Left blank, the person\'s own site is used.', 'TGM Ikeja', format: 'Site name or ID'),
            ImportColumn::make('clocked_in_at', 'When they arrived.', '2026-03-02 08:47', format: 'Date and time, or a bare time'),
            ImportColumn::make('clocked_out_at', 'When they left. Must be after the arrival.', '2026-03-02 17:12', format: 'Date and time, or a bare time'),
            ImportColumn::make('break_minutes', 'Minutes taken as a break, which come off the hours worked.', '45', format: 'Whole number'),
            ImportColumn::make(
                'status',
                'Override the lateness verdict. Left blank it is worked out from the arrival time against the site\'s working day, which is almost always what you want.',
                '',
                format: 'on_time, grace or late',
                accepts: array_column(AttendanceStatus::cases(), 'value'),
            ),
            ImportColumn::make('late_minutes', 'Override how late they were. Only read when the status column is also given.', '', format: 'Whole number'),
        ];
    }

    public function import(ImportRow $row, ImportDuplicates $duplicates, ImportResult $result): void
    {
        $user = $this->staffFor($row);

        if (! $user->clocksIn()) {
            $this->reject("{$user->name} runs the system rather than works a shift, so there is no attendance to record.");
        }

        $date = $row->date('work_date');

        if ($date === null) {
            $this->reject('Give a work date the import can read, such as 2026-03-02.');
        }

        if ($date->isFuture()) {
            $this->reject('That work date is in the future. Attendance is a record of a day already worked.');
        }

        $existing = Attendance::query()
            ->where('user_id', $user->id)
            ->where('work_date', $date->toDateString())
            ->first();

        if ($existing !== null && $duplicates === ImportDuplicates::Skip) {
            $result->skipped();

            return;
        }

        if ($existing !== null && $duplicates === ImportDuplicates::Reject) {
            $this->reject("{$user->name} already has a record for {$date->toDateString()}.");
        }

        $location = $this->location($row, $user, $existing);
        $timezone = $location !== null
            ? $location->timezone
            : (string) config('app.timezone');

        $in = $this->moment($row, 'clocked_in_at', $date, $timezone);
        $out = $this->moment($row, 'clocked_out_at', $date, $timezone);

        if ($in !== null && $out !== null && $out->lessThanOrEqualTo($in)) {
            $this->reject('The clock-out has to be later than the clock-in.');
        }

        $break = $this->validate(
            ['break_minutes' => $row->integer('break_minutes')],
            ['break_minutes' => ['nullable', 'integer', 'between:0,1440']],
        )['break_minutes'];

        [$status, $lateMinutes] = $this->verdict($row, $location, $in);

        // Hours worked are recomputed from whatever the day ends up holding,
        // so correcting only the break on an existing row still moves the
        // total rather than leaving a stale one behind.
        $data = $this->present([
            'location_id' => $location?->id,
            'clocked_in_at' => $in,
            'clocked_out_at' => $out,
            'break_minutes' => $break,
            'status' => $status,
            'late_minutes' => $lateMinutes,
            'worked_minutes' => $this->workedMinutes(
                $in ?? $existing?->clocked_in_at,
                $out ?? $existing?->clocked_out_at,
                (int) ($break ?? $existing?->break_minutes),
            ),
        ]);

        if ($existing !== null) {
            $existing->update($data);
            $result->updated();

            return;
        }

        Attendance::query()->create([
            ...$data,
            'user_id' => $user->id,
            'work_date' => $date->toDateString(),
            // A day loaded without an arrival time is still a day at work.
            // Without this the column falls back to its own default, which
            // would claim the person was on time when nobody said so.
            'status' => $data['status'] ?? AttendanceStatus::OnTime,
            'late_minutes' => $data['late_minutes'] ?? 0,
        ]);

        $result->created();
    }

    /**
     * The site the day was measured against: the one the row names, the one
     * already on the record, or the person's own.
     */
    private function location(ImportRow $row, User $user, ?Attendance $existing): ?Location
    {
        $given = $row->string('location');

        if ($given === null) {
            // Read through the key rather than the relation, so a person with
            // no site comes back as no site rather than as a lazy load that
            // has to be unpicked afterwards.
            $id = $existing !== null ? $existing->location_id : $user->location_id;

            return $id === null ? null : Location::query()->find($id);
        }

        $location = Location::query()
            ->when(
                ctype_digit($given),
                fn ($query) => $query->where('id', (int) $given),
                fn ($query) => $query->whereRaw('LOWER(name) = ?', [mb_strtolower($given)]),
            )
            ->first();

        if ($location === null) {
            $this->reject("No site is called {$given}. Import the sites sheet first.");
        }

        return $location;
    }

    /**
     * A clock time, read in the site's own timezone. A bare time is taken to
     * be on the work date, which is how a terminal export usually writes it.
     */
    private function moment(ImportRow $row, string $column, Carbon $date, string $timezone): ?Carbon
    {
        $value = $row->string($column);

        if ($value === null) {
            return null;
        }

        // A bare time carries no date, so it is hung off the work date rather
        // than off today, which is what Carbon would otherwise assume.
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?(\s?[ap]m)?$/i', $value) === 1) {
            $value = $date->toDateString().' '.$value;
        }

        try {
            return Carbon::parse($value, $timezone);
        } catch (\Throwable) {
            $this->reject("The {$column} value '{$value}' is not a time the import can read.");
        }
    }

    /**
     * Three outcomes, not two: on the hour, inside the site's grace window,
     * or late. Grace is not lateness, so it carries no late minutes.
     *
     * The file may override the verdict, but only by saying so explicitly:
     * a status column is the operator asserting something the times do not
     * show, and one without the other is a half-made claim.
     *
     * @return array{0: AttendanceStatus|null, 1: int|null}
     */
    private function verdict(ImportRow $row, ?Location $location, ?Carbon $in): array
    {
        $given = $row->string('status');

        if ($given !== null) {
            $status = $this->validate(
                ['status' => $given, 'late_minutes' => $row->integer('late_minutes')],
                [
                    'status' => ['required', Rule::enum(AttendanceStatus::class)],
                    'late_minutes' => ['nullable', 'integer', 'between:0,1440'],
                ],
                ['status.enum' => 'Use on_time, grace or late.'],
            );

            return [
                AttendanceStatus::from((string) $status['status']),
                $status['late_minutes'] ?? 0,
            ];
        }

        if ($in === null || $location === null) {
            return [null, null];
        }

        $arrival = $in->copy()->setTimezone($location->timezone)->startOfMinute();
        $start = $location->startOfWorkFor($arrival);
        $cutoff = $location->latenessCutoffFor($arrival);

        if ($arrival->lessThanOrEqualTo($start)) {
            return [AttendanceStatus::OnTime, 0];
        }

        if ($arrival->lessThanOrEqualTo($cutoff)) {
            return [AttendanceStatus::Grace, 0];
        }

        return [AttendanceStatus::Late, (int) $start->diffInMinutes($arrival)];
    }

    /**
     * Time on site less the break, which is how the rest of the app counts a
     * day. Never negative: a break longer than the shift is a data problem,
     * not a debt.
     */
    private function workedMinutes(?CarbonInterface $in, ?CarbonInterface $out, int $break): ?int
    {
        if ($in === null || $out === null) {
            return null;
        }

        return max(0, (int) $in->diffInMinutes($out) - $break);
    }
}
