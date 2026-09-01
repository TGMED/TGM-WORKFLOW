<?php

namespace App\Http\Controllers;

use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Http\Requests\StoreLatenessOnBehalfRequest;
use App\Http\Requests\StoreLeaveOnBehalfRequest;
use App\Models\ApprovalSetting;
use App\Models\Attendance;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\RequestNotifier;
use Illuminate\Http\RedirectResponse;

/**
 * An approver raising a request for a member of staff.
 *
 * Someone signed off sick, or working a site with no device to hand, still
 * needs their leave on the books. The request belongs to them and runs the
 * ordinary chain; the only difference is `raised_by_id`, which records who
 * actually filled the form in.
 */
class OnBehalfRequestController extends Controller
{
    public function __construct(protected RequestNotifier $notifier) {}

    public function leave(StoreLeaveOnBehalfRequest $request): RedirectResponse
    {
        $staff = $this->staff($request->integer('staff_id'));

        $leave = LeaveRequest::query()->create([
            'user_id' => $staff->id,
            'raised_by_id' => $request->user()->id,
            'leave_type_id' => $request->integer('leave_type_id'),
            'supervisor_id' => $request->integer('supervisor_id'),
            'relief_officer_id' => $request->integer('relief_officer_id'),
            'start_date' => $request->startDate(),
            'end_date' => $request->endDate(),
            'days' => $request->days(),
            'reason' => $request->input('reason'),
            'status' => RequestStatus::Pending,
            'approvals_required' => ApprovalSetting::approversRequired(RequestModule::Leave),
        ]);

        $leave->load('leaveType', 'reliefOfficer', 'user', 'raisedBy');

        $this->notifier->raised($leave);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Raised {$leave->summary()} for {$staff->name}. ".
                "It is now with {$leave->reliefOfficer?->name} to agree cover.",
        ]);
    }

    public function lateness(StoreLatenessOnBehalfRequest $request): RedirectResponse
    {
        $staff = $this->staff($request->integer('staff_id'));
        $workDate = $request->workDate();

        // As with a staff member's own explanation, the clock decides how late
        // they were rather than whoever is typing.
        $attendance = Attendance::query()
            ->where('user_id', $staff->id)
            ->where('work_date', $workDate->toDateString())
            ->first();

        $late = LatenessRequest::query()->create([
            'user_id' => $staff->id,
            'raised_by_id' => $request->user()->id,
            'attendance_id' => $attendance?->id,
            'work_date' => $workDate,
            'minutes_late' => $attendance->late_minutes ?? 0,
            'reason' => $request->string('reason')->toString(),
            'status' => RequestStatus::Pending,
            'approvals_required' => ApprovalSetting::approversRequired(RequestModule::Lateness),
        ]);

        $late->load('user', 'raisedBy');

        $this->notifier->raised($late);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Filed {$staff->name}'s explanation for {$late->work_date->format('j M Y')}.",
        ]);
    }

    protected function staff(int $id): User
    {
        return User::query()->findOrFail($id);
    }
}
