<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\RequisitionStatus;
use App\Enums\RetirementStatus;
use App\Http\Requests\RetireRequisitionRequest;
use App\Http\Requests\StoreRequisitionRequest;
use App\Models\Requisition;
use App\Models\Retirement;
use App\Models\User;
use App\Notifications\RequisitionUpdate;
use App\Services\Attachments;
use App\Services\Paystack\PaystackClient;
use App\Support\RequisitionPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Somebody's own requisitions: raising one, withdrawing it while finance has
 * not answered, and retiring it once the money is spent.
 */
class RequisitionController extends Controller
{
    public function __construct(protected Attachments $attachments) {}

    public function index(Request $request, PaystackClient $paystack): Response
    {
        return Inertia::render('Requisitions', [
            'requisitions' => Requisition::query()
                ->with(RequisitionPresenter::relations())
                ->where('requester_id', $request->user()->id)
                ->latest()
                ->limit(200)
                ->get()
                ->map(fn (Requisition $r): array => RequisitionPresenter::row($r))
                ->values(),
            'banks' => array_map(
                fn (array $bank): array => ['value' => $bank['code'], 'label' => $bank['name']],
                $paystack->banks(),
            ),
        ]);
    }

    public function store(StoreRequisitionRequest $request, PaystackClient $paystack): RedirectResponse
    {
        $user = $request->user();

        $requisition = DB::transaction(function () use ($request, $paystack, $user): Requisition {
            $requisition = Requisition::query()->create([
                'requester_id' => $user->id,
                'department_id' => $user->department_id,
                'title' => $request->string('title')->toString(),
                'purpose' => $request->string('purpose')->toString(),
                'amount' => $request->input('amount'),
                'bank_code' => $request->string('bank_code')->toString(),
                'bank_name' => (string) $paystack->bankName($request->string('bank_code')->toString()),
                'account_number' => $request->string('account_number')->toString(),
                'account_name' => $request->accountName(),
                'status' => RequisitionStatus::Pending,
            ]);

            $this->attachments->attach($requisition, $request->file('documents', []), $user);

            return $requisition;
        });

        Notification::send($this->finance(), new RequisitionUpdate($requisition->load('requester'), RequisitionUpdate::RAISED));

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$requisition->reference} is with finance.",
        ]);
    }

    public function destroy(Request $request, Requisition $requisition): RedirectResponse
    {
        abort_unless($requisition->requester_id === $request->user()->id, 403);

        if ($requisition->status !== RequisitionStatus::Pending) {
            return back()->with('toast', ['type' => 'error', 'message' => 'Finance has already answered that one.']);
        }

        $requisition->update(['status' => RequisitionStatus::Withdrawn]);

        return back()->with('toast', ['type' => 'success', 'message' => "{$requisition->reference} has been withdrawn."]);
    }

    /**
     * Account for the money: first time, or again after finance sent the last
     * account back.
     */
    public function retire(RetireRequisitionRequest $request, Requisition $requisition): RedirectResponse
    {
        abort_unless($requisition->requester_id === $request->user()->id, 403);

        $requisition->load('retirement');

        if (! $requisition->awaitsRetirement()) {
            return back()->with('toast', ['type' => 'error', 'message' => 'That requisition has nothing to retire right now.']);
        }

        DB::transaction(function () use ($request, $requisition): void {
            /** @var Retirement $retirement */
            $retirement = $requisition->retirement()->updateOrCreate([], [
                'amount_spent' => $request->input('amount_spent'),
                'notes' => $request->input('notes'),
                'status' => RetirementStatus::Pending,
                'reviewed_by_id' => null,
                'reviewed_at' => null,
            ]);

            $this->attachments->attach($retirement, $request->file('documents', []), $request->user());
        });

        Notification::send(
            $this->finance(),
            new RequisitionUpdate($requisition->refresh()->load(['requester', 'retirement']), RequisitionUpdate::RETIRED),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Your retirement is with finance.']);
    }

    /**
     * @return Collection<int, User>
     */
    protected function finance(): Collection
    {
        return User::query()->active()->withPermission(Permission::ManageRequisitions)->get()->toBase();
    }
}
