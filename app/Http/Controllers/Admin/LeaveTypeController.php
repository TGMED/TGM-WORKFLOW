<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveTypeRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Http\RedirectResponse;

class LeaveTypeController extends Controller
{
    public function store(LeaveTypeRequest $request): RedirectResponse
    {
        $type = LeaveType::query()->create($request->payload(withSlug: true));

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$type->name} has been added. Staff can request it now.",
        ]);
    }

    public function update(LeaveTypeRequest $request, LeaveType $leaveType): RedirectResponse
    {
        $leaveType->update($request->payload());

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$leaveType->name} updated.",
        ]);
    }

    /**
     * Retire or restore a type. Retiring hides it from the request form while
     * leaving the history that refers to it intact.
     */
    public function toggle(LeaveType $leaveType): RedirectResponse
    {
        $activating = ! $leaveType->is_active;

        if (! $activating) {
            $open = LeaveRequest::query()
                ->where('leave_type_id', $leaveType->id)
                ->where('status', RequestStatus::Pending->value)
                ->count();

            if ($open > 0) {
                return back()->with('toast', [
                    'type' => 'error',
                    'message' => "{$leaveType->name} has {$open} request(s) awaiting a decision. Clear those first.",
                ]);
            }
        }

        $leaveType->update(['is_active' => $activating]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $activating
                ? "{$leaveType->name} is available again."
                : "{$leaveType->name} is retired and can no longer be requested.",
        ]);
    }
}
