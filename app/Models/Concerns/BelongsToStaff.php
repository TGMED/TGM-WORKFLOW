<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A record that belongs to a member of staff, and can be narrowed to the
 * staff still on the books.
 *
 * Somebody who has left keeps their history: the rows stay, their own profile
 * still shows them, and the audit trail is untouched. What they stop doing is
 * counting. A company metric answers "how are we doing now", and a leaver's
 * last few months drag on that answer forever if nothing filters them out.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToStaff
{
    /**
     * Rows belonging to staff who are still here.
     *
     * Written as a subquery on the key rather than a relation constraint so
     * it can be read the same way in every model that uses it, and so it
     * composes with the grouped aggregates the dashboards run.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOfActiveStaff(Builder $query): void
    {
        $query->whereIn(
            $query->qualifyColumn('user_id'),
            User::query()->active()->select('users.id'),
        );
    }
}
