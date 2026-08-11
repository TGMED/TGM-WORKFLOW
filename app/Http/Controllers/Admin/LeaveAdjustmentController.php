<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeaveAdjustmentRequest;
use App\Models\LeaveAdjustment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LeaveAdjustmentController extends Controller
{
    /**
     * Move one person's allowance for a leave type, without touching the
     * figure everyone else gets.
     */
    public function store(StoreLeaveAdjustmentRequest $request, User $staff): RedirectResponse
    {
        $adjustment = LeaveAdjustment::query()->create([
            'user_id' => $staff->id,
            'leave_type_id' => $request->integer('leave_type_id'),
            'year' => $request->integer('year'),
            'days' => $request->integer('days'),
            'reason' => $request->string('reason')->toString(),
            'created_by' => $request->user()?->id,
        ]);

        $adjustment->load('leaveType');

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                '%s %s days %s %s %s allowance for %d.',
                $adjustment->days > 0 ? 'Added' : 'Took',
                abs($adjustment->days),
                $adjustment->days > 0 ? 'to' : 'off',
                $staff->name."'s",
                $adjustment->leaveType->name,
                $adjustment->year,
            ),
        ]);
    }

    /**
     * Undo an adjustment that was made in error. The ledger is the record of
     * what the balance is, so a wrong entry comes out rather than being
     * cancelled by an opposite one.
     */
    public function destroy(Request $request, User $staff, LeaveAdjustment $adjustment): RedirectResponse
    {
        abort_unless($adjustment->user_id === $staff->id, 404);

        $adjustment->delete();

        return back()->with('toast', [
            'type' => 'success',
            'message' => "The adjustment has been removed from {$staff->name}'s balance.",
        ]);
    }
}
