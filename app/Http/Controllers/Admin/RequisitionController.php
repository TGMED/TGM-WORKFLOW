<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RequisitionStatus;
use App\Enums\RetirementStatus;
use App\Http\Controllers\Controller;
use App\Models\Requisition;
use App\Notifications\RequisitionUpdate;
use App\Support\PerPage;
use App\Support\RequisitionPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance's desk: every requisition, from approval through payment to the
 * retirement that closes it.
 */
class RequisitionController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'open';

        $requisitions = Requisition::query()
            ->with(RequisitionPresenter::relations())
            ->when($status === 'open', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('status', RequisitionStatus::Pending->value)
                ->orWhere('status', RequisitionStatus::Approved->value)
                ->orWhereHas('retirement', fn (Builder $q) => $q->where('status', RetirementStatus::Pending->value))))
            ->when(RequisitionStatus::tryFrom($status) !== null, fn (Builder $q) => $q->where('status', $status))
            ->latest()
            ->paginate(PerPage::from($request, 25))
            ->withQueryString()
            ->through(fn (Requisition $r): array => RequisitionPresenter::row($r));

        $totals = Requisition::query()
            ->selectRaw('status, count(*) as total, sum(amount) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return Inertia::render('admin/Requisitions', [
            'requisitions' => $requisitions,
            'filters' => ['status' => $status],
            'statuses' => [
                ['value' => 'open', 'label' => 'Needs finance'],
                ['value' => 'all', 'label' => 'Everything'],
                ...RequisitionStatus::options(),
            ],
            'totals' => collect(RequisitionStatus::cases())->mapWithKeys(fn (RequisitionStatus $case): array => [
                $case->value => [
                    'count' => (int) ($totals[$case->value]->total ?? 0),
                    'amount' => (string) ($totals[$case->value]->amount ?? '0'),
                ],
            ])->all(),
            'retirements_waiting' => Requisition::query()
                ->whereHas('retirement', fn (Builder $q) => $q->where('status', RetirementStatus::Pending->value))
                ->count(),
        ]);
    }

    public function decide(Request $request, Requisition $requisition): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'declined'])],
            'note' => ['nullable', 'required_if:decision,declined', 'string', 'max:2000'],
        ], ['note.required_if' => 'Say why it is declined.']);

        if ($requisition->status !== RequisitionStatus::Pending) {
            return back()->with('toast', ['type' => 'error', 'message' => 'That one has already been decided.']);
        }

        $requisition->update([
            'status' => RequisitionStatus::from($validated['decision']),
            'decided_by_id' => $request->user()->id,
            'decided_at' => Carbon::now(),
            'decision_note' => $validated['note'] ?? null,
        ]);

        $requisition->requester->notify(new RequisitionUpdate(
            $requisition,
            $validated['decision'] === 'approved' ? RequisitionUpdate::APPROVED : RequisitionUpdate::DECLINED,
        ));

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$requisition->reference} is ".($validated['decision'] === 'approved' ? 'approved.' : 'declined.'),
        ]);
    }

    public function pay(Request $request, Requisition $requisition): RedirectResponse
    {
        $validated = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);

        if ($requisition->status !== RequisitionStatus::Approved) {
            return back()->with('toast', ['type' => 'error', 'message' => 'Only an approved requisition can be paid.']);
        }

        $requisition->update([
            'status' => RequisitionStatus::Paid,
            'paid_by_id' => $request->user()->id,
            'paid_at' => Carbon::now(),
            'payment_reference' => $validated['payment_reference'] ?? null,
        ]);

        $requisition->requester->notify(new RequisitionUpdate($requisition, RequisitionUpdate::PAID));

        return back()->with('toast', ['type' => 'success', 'message' => "{$requisition->reference} is recorded as paid."]);
    }

    public function review(Request $request, Requisition $requisition): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'queried'])],
            'note' => ['nullable', 'required_if:decision,queried', 'string', 'max:2000'],
        ], ['note.required_if' => 'Say what needs correcting.']);

        $retirement = $requisition->retirement;

        if ($retirement === null || $retirement->status !== RetirementStatus::Pending) {
            return back()->with('toast', ['type' => 'error', 'message' => 'There is no retirement waiting on that one.']);
        }

        $accepted = $validated['decision'] === 'accepted';

        $retirement->update([
            'status' => $accepted ? RetirementStatus::Accepted : RetirementStatus::Queried,
            'reviewed_by_id' => $request->user()->id,
            'reviewed_at' => Carbon::now(),
            'review_note' => $validated['note'] ?? null,
        ]);

        if ($accepted) {
            $requisition->update(['status' => RequisitionStatus::Retired]);
        }

        $requisition->requester->notify(new RequisitionUpdate(
            $requisition->load('retirement'),
            $accepted ? RequisitionUpdate::RETIREMENT_ACCEPTED : RequisitionUpdate::RETIREMENT_QUERIED,
        ));

        return back()->with('toast', [
            'type' => 'success',
            'message' => $accepted ? "{$requisition->reference} is closed." : 'The retirement has gone back to them.',
        ]);
    }
}
