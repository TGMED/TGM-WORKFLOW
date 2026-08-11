<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Collection;

class LeaveBalance
{
    /**
     * Days already spoken for, per leave type, for one person in one year.
     * Pending requests count: a day cannot be promised twice. A request being
     * edited is left out, so its own days do not count against the version
     * replacing them.
     *
     * @return Collection<int, int> keyed by leave type id
     */
    public function usedByType(User $user, int $year, ?int $ignore = null): Collection
    {
        return LeaveRequest::query()
            ->where('user_id', $user->id)
            ->committed()
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore))
            ->inYear($year)
            ->selectRaw('leave_type_id, sum(days) as days_used')
            ->groupBy('leave_type_id')
            ->pluck('days_used', 'leave_type_id')
            ->map(fn ($days): int => (int) $days);
    }

    /**
     * @return array{allowance: int|null, used: int, remaining: int|null}
     */
    public function forType(User $user, LeaveType $type, int $year, ?int $ignore = null): array
    {
        $used = (int) ($this->usedByType($user, $year, $ignore)[$type->id] ?? 0);

        return [
            'allowance' => $type->days_per_year,
            'used' => $used,
            'remaining' => $type->isCapped() ? max(0, $type->days_per_year - $used) : null,
        ];
    }

    /**
     * Every active type with this person's standing against it, ready to hand
     * to the leave page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summary(User $user, int $year): array
    {
        $used = $this->usedByType($user, $year);

        return LeaveType::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(function (LeaveType $type) use ($used): array {
                $taken = (int) ($used[$type->id] ?? 0);

                return [
                    'id' => $type->id,
                    'slug' => $type->slug,
                    'name' => $type->name,
                    'description' => $type->description,
                    'is_paid' => $type->is_paid,
                    'allowance' => $type->days_per_year,
                    'used' => $taken,
                    'remaining' => $type->isCapped() ? max(0, $type->days_per_year - $taken) : null,
                ];
            })
            ->all();
    }
}
