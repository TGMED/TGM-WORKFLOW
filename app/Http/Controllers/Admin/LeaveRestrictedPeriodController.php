<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveRestrictedPeriodRequest;
use App\Models\LeaveRestrictedPeriod;
use Illuminate\Http\RedirectResponse;

class LeaveRestrictedPeriodController extends Controller
{
    public function store(LeaveRestrictedPeriodRequest $request): RedirectResponse
    {
        $period = LeaveRestrictedPeriod::query()->create([
            ...$request->payload(),
            'user_id' => $request->user()?->id,
        ]);

        $period->leaveTypes()->sync($request->exemptLeaveTypeIds());

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$period->name} is closed to leave from {$period->rangeLabel()}.",
        ]);
    }

    /**
     * Change a period. Leave already granted over it stands: closing a window
     * is a rule for the bookings to come, not a decision to take back.
     */
    public function update(LeaveRestrictedPeriodRequest $request, LeaveRestrictedPeriod $restrictedPeriod): RedirectResponse
    {
        $restrictedPeriod->update($request->payload());
        $restrictedPeriod->leaveTypes()->sync($request->exemptLeaveTypeIds());

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$restrictedPeriod->name} updated.",
        ]);
    }

    public function destroy(LeaveRestrictedPeriod $restrictedPeriod): RedirectResponse
    {
        $name = $restrictedPeriod->name;

        $restrictedPeriod->delete();

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$name} has been lifted. Staff can book over those days again.",
        ]);
    }
}
