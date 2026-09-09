<?php

namespace App\Services;

/**
 * What the exit had to clear up, so the person doing it can be told rather
 * than finding out from a colleague whose cover has quietly evaporated.
 */
final readonly class StaffExitOutcome
{
    /**
     * @param  array<int, string>  $jobsVacated  Departments and teams they ran.
     */
    public function __construct(
        public int $requestsCancelled,
        public int $coverToReassign,
        public array $jobsVacated = [],
        public int $requestsReleased = 0,
    ) {}

    /**
     * A sentence for the toast, or null when there was nothing to clear up.
     */
    public function summary(): ?string
    {
        $parts = [];

        if ($this->requestsCancelled > 0) {
            $parts[] = $this->requestsCancelled.' open '.
                ($this->requestsCancelled === 1 ? 'request was' : 'requests were').' withdrawn';
        }

        if ($this->coverToReassign > 0) {
            $parts[] = $this->coverToReassign.' leave '.
                ($this->coverToReassign === 1 ? 'request needs' : 'requests need').
                ' a new relief officer';
        }

        if ($this->requestsReleased > 0) {
            $parts[] = $this->requestsReleased.' '.
                ($this->requestsReleased === 1 ? 'request was' : 'requests were').
                ' waiting on them and has moved on';
        }

        if ($this->jobsVacated !== []) {
            $parts[] = implode(' and ', $this->jobsVacated).
                (count($this->jobsVacated) === 1 ? ' now has' : ' now have').' nobody running it';
        }

        return $parts === [] ? null : ucfirst(implode(', and ', $parts)).'.';
    }
}
