<?php

namespace App\Imports\Concerns;

use App\Imports\ImportColumn;
use App\Imports\ImportRow;
use App\Imports\RowRejected;
use App\Models\User;

/**
 * Every import that hangs something off a person — a profile, an address, a
 * salary, a day's attendance — identifies that person the same way: by staff
 * ID if the company issues them, by email otherwise. Doing it in one place
 * means one set of column headers and one error message across all of them.
 */
trait ResolvesStaff
{
    /**
     * The two columns that name a person, ready to be spread into a
     * columns() list.
     *
     * @return array<int, ImportColumn>
     */
    protected function staffColumns(): array
    {
        return [
            ImportColumn::make(
                name: 'employee_id',
                description: 'The staff ID of the person this row belongs to. Give this or the email; if both are given the staff ID wins.',
                example: 'TGM-0148',
            ),
            ImportColumn::make(
                name: 'email',
                description: 'The work email of the person this row belongs to, used when no staff ID is given.',
                example: 'ada.eze@example.com',
                format: 'Email address',
            ),
        ];
    }

    /**
     * The person a row belongs to. Rejects the row rather than guessing:
     * writing somebody's salary or medical details onto the wrong record is
     * not a mistake a bulk import gets to make quietly.
     */
    protected function staffFor(ImportRow $row): User
    {
        $employeeId = $row->string('employee_id');
        $email = $row->string('email');

        if ($employeeId === null && $email === null) {
            throw RowRejected::because('Give either a staff ID or an email so the row can be matched to a person.');
        }

        if ($employeeId !== null) {
            $user = User::query()->where('employee_id', $employeeId)->first();

            if ($user === null) {
                throw RowRejected::because("No member of staff has the staff ID {$employeeId}.");
            }

            // A file carrying both is worth a second look: it usually means
            // two rows have been merged, or a column has slipped sideways.
            if ($email !== null && mb_strtolower($user->email) !== mb_strtolower($email)) {
                throw RowRejected::because(
                    "Staff ID {$employeeId} belongs to {$user->email}, not {$email}. Correct one of the two.",
                );
            }

            return $user;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            throw RowRejected::because("No member of staff has the email {$email}. Import them on the staff sheet first.");
        }

        return $user;
    }
}
