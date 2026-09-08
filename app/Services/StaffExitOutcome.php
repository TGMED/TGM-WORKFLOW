<?php

namespace App\Services;

/**
 * What the exit had to clear up, so the person doing it can be told rather
 * than finding out from a colleague whose cover has quietly evaporated.
 */
final readonly class StaffExitOutcome
{
    public function __construct(
        public int $requestsCancelled,
        public int $coverToReassign,
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

        return $parts === [] ? null : ucfirst(implode(', and ', $parts)).'.';
    }
}
