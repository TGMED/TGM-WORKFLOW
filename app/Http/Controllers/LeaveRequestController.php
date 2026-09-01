<?php

namespace App\Http\Controllers;

use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Http\Requests\StoreLeaveRequest;
use App\Http\Requests\UpdateLeaveRequest;
use App\Models\ApprovalSetting;
use App\Models\LeaveRequest;
use App\Models\LeaveRestrictedPeriod;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\LeaveBalance;
use App\Services\RequestNotifier;
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
        protected RequestNotifier $notifier,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $year = (int) $request->integer('year', Carbon::now()->year);

        $requests = LeaveRequest::query()
            ->with(['leaveType', 'supervisor:id,name', 'reliefOfficer:id,name', 'approvals.approver:id,name'])
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
            'supervisors' => $this->supervisors($user),
            'cover_duties' => $this->coverDuties($user),
            'relief_officers' => $this->reliefOfficers($user),
            // The days the business has closed to leave, already worked out
            // against this person, so the form can say no before the server
            // has to.
            'restricted_periods' => $this->restrictedPeriods($user),
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
            'supervisor_id' => $request->integer('supervisor_id'),
            'relief_officer_id' => $request->integer('relief_officer_id'),
            'start_date' => $request->startDate(),
            'end_date' => $request->endDate(),
            'days' => $request->days(),
            'reason' => $request->input('reason'),
            'status' => RequestStatus::Pending,
            // Snapshot the setting, so changing it later cannot move the
            // goalposts on a request already in flight.
            'approvals_required' => ApprovalSetting::approversRequired(RequestModule::Leave),
        ]);

        $leave->load('leaveType', 'reliefOfficer');

        $this->notifier->raised($leave);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Requested {$leave->summary()}. It is now with {$leave->reliefOfficer?->name} to agree cover.",
        ]);
    }

    /**
     * Change a request nobody has ruled on yet, or redo one that was sent
     * back. The approvals snapshot stays as it was raised, so an edit cannot
     * dodge a setting change either way.
     */
    public function update(UpdateLeaveRequest $request, LeaveRequest $leave): RedirectResponse
    {
        if (! $leave->isEditable()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'That request has already been acted on and can no longer be changed.',
            ]);
        }

        // A returned request goes round the chain again from the top. The
        // decisions that sent it back stay on the trail, but a new round means
        // the same people are asked afresh rather than being counted as done.
        $resubmitting = $leave->needsResubmitting();

        // A change of hands is worth an email; a change of dates on a request
        // nobody has seen yet is not, since the same people are still waiting
        // on the same thing.
        $handedOver = $leave->relief_officer_id !== $request->integer('relief_officer_id')
            || $leave->supervisor_id !== $request->integer('supervisor_id');

        $leave->update([
            'leave_type_id' => $request->integer('leave_type_id'),
            'supervisor_id' => $request->integer('supervisor_id'),
            'relief_officer_id' => $request->integer('relief_officer_id'),
            'start_date' => $request->startDate(),
            'end_date' => $request->endDate(),
            'days' => $request->days(),
            'reason' => $request->input('reason'),
            ...$resubmitting ? [
                'status' => RequestStatus::Pending,
                'round' => $leave->round + 1,
                'decided_at' => null,
            ] : [],
        ]);

        $leave->load('leaveType', 'reliefOfficer');

        if ($resubmitting || $handedOver) {
            $this->notifier->raised($leave);
        }

        $lead = $resubmitting ? 'Resubmitted as' : 'Updated to';

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$lead} {$leave->summary()}. It is with {$leave->reliefOfficer?->name} to agree cover.",
        ]);
    }

    /**
     * Withdraw a request that has not been settled. One sent back for a redo
     * counts: dropping it is the alternative to resubmitting.
     */
    public function destroy(Request $request, LeaveRequest $leave): RedirectResponse
    {
        abort_unless($leave->user_id === $request->user()->id, 403);

        if (! $leave->status->isOpen() && ! $leave->needsResubmitting()) {
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
     * Periods closed to leave, as they apply to this person: whether their
     * marital status lets them through, and which types of leave the period
     * leaves alone. Past windows come too, since leave can be backdated into
     * one and the server would turn that away.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function restrictedPeriods(User $user): array
    {
        $exemptible = $user->profile?->marital_status === null;

        return LeaveRestrictedPeriod::query()
            ->with('leaveTypes:id')
            ->orderBy('start_date')
            ->get()
            ->map(fn (LeaveRestrictedPeriod $period): array => [
                'name' => $period->name,
                'reason' => $period->reason,
                'start' => $period->start_date->toDateString(),
                'end' => $period->end_date->toDateString(),
                'range_label' => $period->rangeLabel(),
                'exempt' => $period->exempts($user),
                'allowed_type_ids' => $period->leaveTypes->pluck('id')->all(),
                // Worth telling somebody the window has a door they might fit
                // through, but whose profile does not say either way.
                'needs_marital_status' => $exemptible && $period->exempt_marital_statuses !== [],
            ])
            ->all();
    }

    /**
     * Days this person has agreed to hold the fort for someone else, which
     * they cannot book leave over.
     *
     * @return array<int, array{start: string, end: string, colleague: string}>
     */
    protected function coverDuties(User $user): array
    {
        return LeaveRequest::query()
            ->with('user:id,name')
            ->coveredBy($user->id)
            ->where('end_date', '>=', Carbon::now()->toDateString())
            ->orderBy('start_date')
            ->get()
            ->map(fn (LeaveRequest $leave): array => [
                'start' => $leave->start_date->toDateString(),
                'end' => $leave->end_date->toDateString(),
                'colleague' => $leave->user->name,
            ])
            ->all();
    }

    /**
     * Approvers this person may send a request to.
     *
     * @return array<int, array{value: int, label: string}>
     */
    protected function supervisors(User $user): array
    {
        return User::query()
            ->active()
            ->withRole(Role::APPROVER, Role::SUPER_ADMIN)
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'position'])
            ->map(fn (User $approver): array => [
                'value' => $approver->id,
                'label' => $approver->position === null
                    ? $approver->name
                    : "{$approver->name} · {$approver->position}",
            ])
            ->all();
    }

    /**
     * Anyone still on the books can cover a desk, approver or not. Each one
     * carries the leave they already have booked, so the form can drop the
     * people who will be away over the days being asked for.
     *
     * @return array<int, array{value: int, label: string, away: array<int, array{start: string, end: string}>}>
     */
    protected function reliefOfficers(User $user): array
    {
        $away = LeaveRequest::query()
            ->committed()
            ->where('end_date', '>=', Carbon::now()->toDateString())
            ->get(['user_id', 'start_date', 'end_date'])
            ->groupBy('user_id');

        return User::query()
            ->active()
            ->clocksIn()
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'department'])
            ->map(fn (User $colleague): array => [
                'value' => $colleague->id,
                'label' => $colleague->department === null
                    ? $colleague->name
                    : "{$colleague->name} · {$colleague->department}",
                'away' => $away->get($colleague->id, collect())
                    ->map(fn (LeaveRequest $leave): array => [
                        'start' => $leave->start_date->toDateString(),
                        'end' => $leave->end_date->toDateString(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();
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
            'supervisor' => $leave->supervisor?->name,
            'supervisor_id' => $leave->supervisor_id,
            'relief_officer' => $leave->reliefOfficer?->name,
            'relief_officer_id' => $leave->relief_officer_id,
            'can_edit' => $leave->isEditable(),
            'needs_resubmit' => $leave->needsResubmitting(),
            'stage_label' => $leave->stageLabel(),
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
