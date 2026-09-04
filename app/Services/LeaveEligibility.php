<?php

namespace App\Services;

use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Whether the policy lets somebody take a kind of leave at all, ahead of any
 * question of how many days are left. The leave form and the validator both
 * read it from here, so a person is never offered a type the server will then
 * turn away.
 */
class LeaveEligibility
{
    /**
     * @return array{eligible: bool, reason: string|null}
     */
    public function check(User $user, LeaveType $type, ?Carbon $on = null): array
    {
        $on ??= Carbon::now()->startOfDay();

        foreach ([$this->serviceReason(...), $this->probationReason(...)] as $rule) {
            $reason = $rule($user, $type, $on);

            if ($reason !== null) {
                return ['eligible' => false, 'reason' => $reason];
            }
        }

        return ['eligible' => true, 'reason' => null];
    }

    public function passes(User $user, LeaveType $type, ?Carbon $on = null): bool
    {
        return $this->check($user, $type, $on)['eligible'];
    }

    /**
     * Time served. Somebody with no start date on file cannot be measured, so
     * the rule stands aside rather than shutting them out on a blank field.
     */
    protected function serviceReason(User $user, LeaveType $type, Carbon $on): ?string
    {
        if ($type->min_service_months === 0) {
            return null;
        }

        $served = $user->serviceMonths($on);

        if ($served === null || $served >= $type->min_service_months) {
            return null;
        }

        $opens = $user->hired_at->copy()->addMonths($type->min_service_months);

        return sprintf(
            '%s opens up after %s of service. Yours starts on %s.',
            $type->name,
            $type->serviceRequirement(),
            $opens->format('j M Y'),
        );
    }

    /**
     * Probation. The policy keeps a few types back until somebody has been
     * confirmed in post.
     */
    protected function probationReason(User $user, LeaveType $type, Carbon $on): ?string
    {
        if (! $type->requires_confirmed || $user->isConfirmed()) {
            return null;
        }

        return "{$type->name} is for confirmed staff. Yours opens up once your probation has been signed off.";
    }
}
