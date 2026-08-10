<?php

namespace App\Http\Controllers;

use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Http\Requests\StoreLeaveRequest;
use App\Models\ApprovalSetting;
use App\Models\LeaveRequest;
use App\Services\ApprovalService;
use App\Services\LeaveBalance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class LeaveRequestController extends Controller
{
    public function __construct(
        protected LeaveBalance $balances,
        protected ApprovalService $approvals,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $year = (int) $request->integer('year', Carbon::now()->year);

        $requests = LeaveRequest::query()
            ->with(['leaveType', 'approvals.approver:id,name'])
            ->where('user_id', $user->id)
            ->orderByDesc('start_date')
            ->limit(60)
            ->get();

        return Inertia::render('Leave', [
            'year' => $year,
            'balances' => $this->balances->summary($user, $year),
            // The form caps its own date picker with these, counting the same
            // working days the server will count when the request lands.
            'workdays' => $user->location?->workdayNumbers() ?? [1, 2, 3, 4, 5],
            'requests' => $requests->map(fn (LeaveRequest $leave): array => $this->payload($leave))->values(),
            'approvers_required' => ApprovalSetting::approversRequired(RequestModule::Leave),
            'stats' => [
                'pending' => $requests->where('status', RequestStatus::Pending)->count(),
                'approved_days' => $requests
                    ->where('status', RequestStatus::Approved)
                    ->filter(fn (LeaveRequest $leave): bool => $leave->start_date->year === $year)
                    ->sum('days'),
            ],
        ]);
    }

    public function store(StoreLeaveRequest $request): RedirectResponse
    {
        $leave = LeaveRequest::query()->create([
            'user_id' => $request->user()->id,
            'leave_type_id' => $request->integer('leave_type_id'),
            'start_date' => $request->startDate(),
            'end_date' => $request->endDate(),
            'days' => $request->days(),
            'reason' => $request->input('reason'),
            'status' => RequestStatus::Pending,
            // Snapshot the setting, so changing it later cannot move the
            // goalposts on a request already in flight.
            'approvals_required' => ApprovalSetting::approversRequired(RequestModule::Leave),
        ]);

        $leave->load('leaveType');

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Requested {$leave->summary()}. It is now with your approver.",
        ]);
    }

    /**
     * Withdraw a request that has not been decided yet.
     */
    public function destroy(Request $request, LeaveRequest $leave): RedirectResponse
    {
        abort_unless($leave->user_id === $request->user()->id, 403);

        if (! $leave->status->isOpen()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'That request has already been decided and cannot be withdrawn.',
            ]);
        }

        $leave->update([
            'status' => RequestStatus::Cancelled,
            'decided_at' => Carbon::now(),
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Your leave request has been withdrawn.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(LeaveRequest $leave): array
    {
        return [
            'id' => $leave->id,
            'type' => $leave->leaveType->name,
            'type_id' => $leave->leave_type_id,
            'start_date' => $leave->start_date->toDateString(),
            'end_date' => $leave->end_date->toDateString(),
            'range_label' => $leave->start_date->format('j M Y').' to '.$leave->end_date->format('j M Y'),
            'days' => $leave->days,
            'reason' => $leave->reason,
            'status' => $leave->status->value,
            'status_label' => $leave->status->label(),
            'status_tone' => $leave->status->tone(),
            'approvals_given' => $leave->approvalsGiven(),
            'approvals_required' => $leave->approvals_required,
            'decided_at' => $leave->decided_at?->toIso8601String(),
            'created_at' => $leave->created_at?->toIso8601String(),
            'trail' => $this->approvals->trail($leave),
        ];
    }
}
