<?php

namespace App\Http\Controllers;

use App\Contracts\Approvable;
use App\Enums\ApprovalDecision;
use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Models\Approval;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Database\Eloquent\Builder;
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

        return back()->with('toast', [
            'type' => 'success',
            'message' => $this->outcomeMessage($subject, $decision),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function openLeave(User $approver): array
    {
        return LeaveRequest::query()
            ->with(['user:id,name,department,position,location_id', 'user.location:id,name', 'leaveType', 'approvals.approver:id,name'])
            ->where('status', RequestStatus::Pending->value)
            ->where('user_id', '!=', $approver->id)
            ->whereDoesntHave('approvals', fn (Builder $query) => $query->where('approver_id', $approver->id))
            ->orderBy('start_date')
            ->get()
            ->map(fn (LeaveRequest $leave): array => [
                ...$this->common($leave),
                'type' => $leave->leaveType->name,
                'range_label' => $leave->start_date->format('j M').' to '.$leave->end_date->format('j M Y'),
                'days' => $leave->days,
                'reason' => $leave->reason,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function openLateness(User $approver): array
    {
        return LatenessRequest::query()
            ->with(['user:id,name,department,position,location_id', 'user.location:id,name', 'approvals.approver:id,name'])
            ->where('status', RequestStatus::Pending->value)
            ->where('user_id', '!=', $approver->id)
            ->whereDoesntHave('approvals', fn (Builder $query) => $query->where('approver_id', $approver->id))
            ->orderByDesc('work_date')
            ->get()
            ->map(fn (LatenessRequest $late): array => [
                ...$this->common($late),
                'day_label' => $late->work_date->format('D, j M Y'),
                'minutes_late' => $late->minutes_late,
                'reason' => $late->reason,
            ])
            ->all();
    }

    /**
     * Fields every card in the inbox shows, whatever the request is.
     *
     * @param  Approvable&Model  $subject
     * @return array<string, mixed>
     */
    protected function common(Approvable $subject): array
    {
        $requester = $subject->requester();

        return [
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
                'department' => $requester->department,
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
    protected function outcomeMessage(Approvable $subject, ApprovalDecision $decision): string
    {
        $name = $subject->requester()->name;

        if ($decision === ApprovalDecision::Rejected) {
            return "Declined {$name}'s request.";
        }

        $outstanding = $subject->load('approvals')->approvalsOutstanding();

        return $outstanding === 0
            ? "Approved {$name}'s request. It is now granted."
            : "Approved. {$name}'s request still needs {$outstanding} more approval(s).";
    }
}
