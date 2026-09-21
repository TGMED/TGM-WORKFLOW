<?php

namespace App\Http\Controllers;

use App\Enums\OutOfOfficeKind;
use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Http\Requests\StoreOutOfOfficeRequest;
use App\Models\ApprovalSetting;
use App\Models\OutOfOfficeRequest;
use App\Models\PublicHoliday;
use App\Services\ApprovalService;
use App\Services\RequestNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Days worked away from the office. Not leave: nothing comes off an
 * allowance, and the roster shows the person as working rather than away.
 */
class OutOfOfficeController extends Controller
{
    public function __construct(
        protected ApprovalService $approvals,
        protected RequestNotifier $notifier,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $requests = OutOfOfficeRequest::query()
            ->with('approvals.approver:id,name')
            ->where('user_id', $user->id)
            ->orderByDesc('start_date')
            ->limit(60)
            ->get();

        $thisYear = $requests
            ->where('status', RequestStatus::Approved)
            ->filter(fn (OutOfOfficeRequest $row): bool => $row->start_date->year === Carbon::now()->year);

        return Inertia::render('OutOfOffice', [
            'requests' => $requests->map(fn (OutOfOfficeRequest $row): array => $this->payload($row))->values(),
            'kinds' => OutOfOfficeKind::options(),
            // The form counts the same working days the server will count.
            'workdays' => $user->location?->workdayNumbers() ?? [1, 2, 3, 4, 5],
            // Public holidays are never counted, so the form leaves them out
            // too and says which ones it left out: the company-wide ones and
            // those of this person's own site.
            'holidays' => PublicHoliday::between(
                Carbon::now()->subYear()->startOfYear(),
                Carbon::now()->addYears(2)->endOfYear(),
                $user->location_id,
            ),
            'stats' => [
                'pending' => $requests->where('status', RequestStatus::Pending)->count(),
                'days_this_year' => (int) $thisYear->sum('days'),
            ],
        ]);
    }

    public function store(StoreOutOfOfficeRequest $request): RedirectResponse
    {
        $away = OutOfOfficeRequest::query()->create([
            'user_id' => $request->user()->id,
            'kind' => $request->kind(),
            'start_date' => $request->startDate(),
            'end_date' => $request->endDate(),
            'days' => $request->days(),
            'reason' => $request->string('reason')->toString(),
            'destination' => $request->input('destination'),
            'contact_number' => $request->input('contact_number'),
            'status' => RequestStatus::Pending,
            'approvals_required' => ApprovalSetting::approversRequired(RequestModule::OutOfOffice),
        ]);

        $this->notifier->raised($away);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Filed for '.$away->dateRange().'.',
        ]);
    }

    /**
     * Withdraw one still waiting on a decision. A decided request stays as it
     * is: it is a record of days already agreed or refused.
     */
    public function destroy(Request $request, OutOfOfficeRequest $outOfOffice): RedirectResponse
    {
        abort_unless($outOfOffice->user_id === $request->user()->id, 403);

        if (! $outOfOffice->status->isOpen()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'That request has already been decided and cannot be withdrawn.',
            ]);
        }

        $outOfOffice->update([
            'status' => RequestStatus::Cancelled,
            'decided_at' => Carbon::now(),
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Your request has been withdrawn.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(OutOfOfficeRequest $away): array
    {
        return [
            'id' => $away->id,
            'kind' => $away->kind->value,
            'kind_label' => $away->kind->label(),
            'kind_tone' => $away->kind->tone(),
            'start_date' => $away->start_date->toDateString(),
            'end_date' => $away->end_date->toDateString(),
            'range_label' => $away->dateRange(),
            'days' => $away->days,
            'reason' => $away->reason,
            'destination' => $away->destination,
            'contact_number' => $away->contact_number,
            'status' => $away->status->value,
            'status_label' => $away->status->label(),
            'status_tone' => $away->status->tone(),
            'stage_label' => $away->stageLabel(),
            'approvals_given' => $away->approvalsGiven(),
            'approvals_required' => $away->approvals_required,
            'decided_at' => $away->decided_at?->toIso8601String(),
            'created_at' => $away->created_at?->toIso8601String(),
            'trail' => $this->approvals->trail($away),
        ];
    }
}
