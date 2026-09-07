<?php

namespace App\Enums;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A date on the employee record that an entitlement hangs off. Types with an
 * anchor are claimable for a window after it and lapse once that passes —
 * birthday leave being the policy's one example, six months and it is gone.
 */
enum LeaveAnchor: string
{
    case Birthday = 'birthday';
    case HireDate = 'hire_date';

    public function label(): string
    {
        return match ($this) {
            self::Birthday => 'their birthday',
            self::HireDate => 'their start date',
        };
    }

    /**
     * The most recent occurrence of the anchor on or before the day given.
     * Null when the record does not carry the date it needs, which leaves the
     * window unenforceable rather than closed.
     */
    public function lastOccurrenceFor(User $user, Carbon $on): ?Carbon
    {
        $date = match ($this) {
            self::Birthday => $user->profile?->date_of_birth,
            self::HireDate => $user->hired_at,
        };

        if ($date === null) {
            return null;
        }

        $anniversary = Carbon::parse($date)->setYear($on->year)->startOfDay();

        return $anniversary->greaterThan($on)
            ? $anniversary->subYear()
            : $anniversary;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $anchor): array => ['value' => $anchor->value, 'label' => $anchor->label()],
            self::cases(),
        );
    }
}
