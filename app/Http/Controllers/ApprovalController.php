<?php

namespace App\Http\Controllers;

use App\Contracts\Approvable;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStage;
use App\Enums\Permission;
use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Models\Approval;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalController extends Controller
{
    public function __construct(protected ApprovalService $approvals) {}

    public function index(Request $request): Response
    {
        $approver = $request->user();

        return Inertia::render('Approvals', [
            'leave' => $this->openLeave($approver),
            'lateness' => $this->openLateness($approver),
            'history' => $this->history($approver),
            // Only approvers may file for someone else, and a relief officer
            // reaches this page without being one.
            'raise' => $approver->canApprove() ? $this->raiseOptions($approver) : null,
        ]);
    }

    /**
     * Record this approver's decision on one request.
     */
    public function store(Request $request, string $module, int $id): RedirectResponse
    {
        $requestModule = RequestModule::tryFrom($module);

        abort_if($requestModule === null, 404);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $decision = ApprovalDecision::from($validated['decision']);

        /** @var Approvable&Model $subject */
        $subject = $requestModule->model()::query()
            ->with('approvals')
            ->findOrFail($id);

        // Read the stage before the decision lands, so the message can say
        // what the approver just did rather than where the request went next.
        $stage = $subject->approvalStageFor($request->user());

        $recorded = $this->approvals->decide(
            $subject,
            $request->user(),
            $decision,
            $validated['comment'] ?? null,
        );

        if (! $recorded) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'That request is no longer waiting on you.',
            ]);
        }

        $subject->refresh();

        $toast = [
            'type' => 'success',
            'message' => $this->outcomeMessage($subject, $decision, $stage),
        ];

        // A relief officer is only here on the strength of the cover they
        // owe. Answering it can be the last thing waiting on them, and going
        // "back" would then put them on a page that is no longer theirs to
        // see. They go to their own instead, told what they just did.
        return $request->user()->usesApprovals()
            ? back()->with('toast', $toast)
            : redirect()->route('dashboard')->with('toast', $toast);
    }

    /**
     * The lists behind the "raise for a colleague" form: who it can be for,
     * what they can be booked off for, and who rules on it.
     *
     * @return array<string, mixed>
     */
    protected function raiseOptions(User $approver): array
    {
        // A head of department or a team lead files for their own people. Only
        // somebody who approves for the company sees the whole staff list.
        $managed = $approver->managedUserIds();

        $staff = User::query()
            ->active()
            ->clocksIn()
            ->whereKeyNot($approver->id)
            ->when(
                ! $approver->approvesCompanyWide(),
                fn ($query) => $query->whereKey($managed),
            )
            ->orderBy('name')
            ->with('department:id,name')
            ->get(['id', 'name', 'department_id', 'location_id']);

        return [
            'staff' => $staff
                ->map(fn (User $person): array => [
                    'value' => $person->id,
                    'label' => $person->department === null
                        ? $person->name
                        : "{$person->name} · {$person->department->name}",
                    // Carried so the form can narrow the cover list to the
                    // chosen person's own department, which is not known until
                    // they are picked.
                    'department_id' => $person->department_id,
                ])
                ->all(),
            'leave_types' => LeaveType::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (LeaveType $type): array => ['value' => $type->id, 'label' => $type->name])
                ->all(),
            // The filer cannot approve what they filed, so they are not on
            // the list of approvers they can name.
            'approvers' => User::query()
                ->active()
                ->withPermission(Permission::ApproveRequests)
                ->whereKeyNot($approver->id)
                ->orderBy('name')
                ->get(['id', 'name', 'position'])
                ->map(fn (User $person): array => [
                    'value' => $person->id,
                    'label' => $person->position === null
                        ? $person->name
                        : "{$person->name} · {$person->position}",
                ])
                ->all(),
            // Cover comes from the requester's own department, so the whole
            // list goes over and the form narrows it once a person is chosen.
            // The filer is included: an approver may well be the cover. Cut to
            // their own people for a head or lead, who can only file for those
            // anyway, so the roster is never handed over wholesale.
            'colleagues' => User::query()
                ->active()
                ->clocksIn()
                ->when(
                    ! $approver->approvesCompanyWide(),
                    fn ($query) => $query->whereKey([...$managed, $approver->id]),
                )
                ->orderBy('name')
                ->with('department:id,name')
                ->get(['id', 'name', 'department_id'])
                ->map(fn (User $person): array => [
                    'value' => $person->id,
                    'label' => $person->department === null
                        ? $person->name
                        : "{$person->name} · {$person->department->name}",
                    'department_id' => $person->department_id,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function openLeave(User $approver): array
    {
        return $this->approvals->awaitingLeave($approver)
            ->load(['user:id,name,department_id,position,location_id', 'user.department:id,name', 'user.location:id,name', 'leaveType', 'supervisor:id,name', 'reliefOfficer:id,name', 'approvals.approver:id,name'])
            ->sortBy('start_date')
            ->map(fn (LeaveRequest $leave): array => [
                ...$this->common($leave, $approver),
                'type' => $leave->leaveType->name,
                'range_label' => $leave->start_date->format('j M').' to '.$leave->end_date->format('j M Y'),
                'days' => $leave->days,
                'reason' => $leave->reason,
                'supervisor' => $leave->supervisor?->name,
                'relief_officer' => $leave->reliefOfficer?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function openLateness(User $approver): array
    {
        return $this->approvals->awaitingLateness($approver)
            ->load(['user:id,name,department_id,position,location_id', 'user.department:id,name', 'user.location:id,name', 'approvals.approver:id,name'])
            ->sortByDesc('work_date')
            ->map(fn (LatenessRequest $late): array => [
                ...$this->common($late, $approver),
                'day_label' => $late->work_date->format('D, j M Y'),
                'minutes_late' => $late->minutes_late,
                'reason' => $late->reason,
            ])
            ->values()
            ->all();
    }

    /**
     * Fields every card in the inbox shows, whatever the request is.
     *
     * @param  Approvable&Model  $subject
     * @return array<string, mixed>
     */
    protected function common(Approvable $subject, User $approver): array
    {
        $requester = $subject->requester();
        $stage = $subject->approvalStageFor($approver);

        return [
            'stage' => $stage->value,
            'stage_label' => $stage->label(),
            'id' => $subject->getKey(),
            'module' => $subject->module()->value,
            'summary' => $subject->summary(),
            'requested_at' => $subject->raisedAt()?->toIso8601String(),
            'approvals_given' => $subject->approvalsGiven(),
            'approvals_required' => $subject->approvalsRequired(),
            'requester' => [
                'id' => $requester->id,
                'name' => $requester->name,
                'initials' => $requester->initials,
                'department' => $requester->department?->name,
                'position' => $requester->position,
                'location' => $requester->location?->name,
            ],
            'trail' => $this->approvals->trail($subject),
        ];
    }

    /**
     * This approver's own recent decisions, so the page shows what they did as
     * well as what is left.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function history(User $approver): array
    {
        return Approval::query()
            ->with('approvable')
            ->where('approver_id', $approver->id)
            ->latest('decided_at')
            ->limit(25)
            ->get()
            ->filter(fn (Approval $approval): bool => $approval->approvable instanceof Approvable)
            ->map(function (Approval $approval): array {
                /** @var Approvable&Model $subject */
                $subject = $approval->approvable;

                return [
                    'id' => $approval->id,
                    'module' => $subject->module()->value,
                    'summary' => $subject->summary(),
                    'requester' => $subject->requester()->name,
                    'decision' => $approval->decision->value,
                    'decision_label' => $approval->decision->label(),
                    'comment' => $approval->comment,
                    'decided_at' => $approval->decided_at->toIso8601String(),
                    'outcome' => $subject->requestStatus()->label(),
                    'outcome_tone' => $subject->requestStatus()->tone(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Approvable&Model  $subject
     */
    protected function outcomeMessage(
        Approvable $subject,
        ApprovalDecision $decision,
        ApprovalStage $stage,
    ): string {
        $name = $subject->requester()->name;

        if ($decision === ApprovalDecision::Rejected) {
            return $subject->requestStatus() === RequestStatus::Returned
                ? "Sent {$name}'s request back to them."
                : "Declined {$name}'s request.";
        }

        if ($stage === ApprovalStage::Relief) {
            return "Cover agreed for {$name}. It is now with their approver.";
        }

        $outstanding = $subject->load('approvals')->approvalsOutstanding();

        return $outstanding === 0
            ? "Approved {$name}'s request. It is now granted."
            : "Approved. {$name}'s request still needs {$outstanding} more approval(s).";
    }
}
