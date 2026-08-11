<?php

namespace App\Http\Requests;

use App\Models\LeaveRequest;

/**
 * Changing a request already raised. The rules are the booking rules, minus
 * the request's own days, which it should not be measured against.
 */
class UpdateLeaveRequest extends StoreLeaveRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && $this->leave()->user_id === $this->user()->id;
    }

    protected function editing(): LeaveRequest
    {
        return $this->leave();
    }

    protected function leave(): LeaveRequest
    {
        /** @var LeaveRequest $leave */
        $leave = $this->route('leave');

        return $leave;
    }
}
