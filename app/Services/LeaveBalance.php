<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class LeaveBalance
{
    public function __construct(protected LeaveEligibility $eligibility) {}

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
        $allowance = $type->allowanceFor($user);

        return [
            'allowance' => $allowance,
            'used' => $used,
            'remaining' => $allowance === null ? null : max(0, $allowance - $used),
        ];
    }

    /**
     * Every active type with this person's standing against it, ready to hand
     * to the leave page. Types the policy closes to them come too, carrying
     * the reason, so the form can grey one out and say why rather than
     * quietly leaving it off the list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summary(User $user, int $year): array
    {
        $used = $this->usedByType($user, $year);
        $today = Carbon::now()->startOfDay();

        return LeaveType::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(function (LeaveType $type) use ($user, $used, $today): array {
                $taken = (int) ($used[$type->id] ?? 0);
                $allowance = $type->allowanceFor($user);
                $window = $type->windowFor($user, $today);
                $eligibility = $this->eligibility->check($user, $type, $today);

                return [
                    'id' => $type->id,
                    'slug' => $type->slug,
                    'name' => $type->name,
                    'description' => $type->description,
                    'is_paid' => $type->is_paid,
                    'allowance' => $allowance,
                    'used' => $taken,
                    'remaining' => $allowance === null ? null : max(0, $allowance - $taken),
                    'eligible' => $eligibility['eligible'],
                    'ineligible_reason' => $eligibility['reason'],
                    'requires_evidence' => $type->requires_evidence,
                    // The days an expiring entitlement may be booked over, so
                    // the form can cap its own picker the way the server will.
                    'window' => $window === null ? null : [
                        'start' => $window['start']->toDateString(),
                        'end' => $window['end']->toDateString(),
                        'label' => $window['start']->format('j M Y').' to '.$window['end']->format('j M Y'),
                    ],
                ];
            })
            ->all();
    }
}
