<?php

namespace App\Http\Controllers;

use App\Models\StaffAction;
use App\Services\ConductNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The queries, warnings and confirmations somebody has been issued, where
 * they answer a query or say they have read the rest.
 */
class ConductController extends Controller
{
    public function __construct(protected ConductNotifier $notifier) {}

    public function index(Request $request): Response
    {
        $actions = StaffAction::query()
            ->with(['issuedBy:id,name', 'offence:id,title'])
            ->where('subject_user_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (StaffAction $action): array => [
                'id' => $action->id,
                'kind' => $action->kind->value,
                'kind_label' => $action->kind->label(),
                'kind_tone' => $action->kind->tone(),
                'title' => $action->title,
                'body' => $action->body,
                'offence' => $action->offence?->title,
                'issued_by' => $action->issuedBy?->name,
                'response_due_on' => $action->response_due_on?->toDateString(),
                'response' => $action->response,
                'responded_at' => $action->responded_at?->toIso8601String(),
                'acknowledged_at' => $action->acknowledged_at?->toIso8601String(),
                'expects_response' => $action->kind->expectsResponse(),
                'state_label' => $action->stateLabel(),
                'state_tone' => $action->stateTone(),
                'created_at' => $action->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Conduct', ['actions' => $actions]);
    }

    public function respond(Request $request, StaffAction $action): RedirectResponse
    {
        abort_unless($action->subject_user_id === $request->user()->id, 403);

        if (! $action->awaitsResponse()) {
            return back()->with('toast', ['type' => 'error', 'message' => 'That one does not need an answer from you.']);
        }

        $validated = $request->validate([
            'response' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $action->update([
            'response' => $validated['response'],
            'responded_at' => Carbon::now(),
        ]);

        $this->notifier->answered($action);

        return back()->with('toast', ['type' => 'success', 'message' => 'Your answer has gone to HR and your head of department.']);
    }

    public function acknowledge(Request $request, StaffAction $action): RedirectResponse
    {
        abort_unless($action->subject_user_id === $request->user()->id, 403);

        if ($action->kind->expectsResponse() || $action->acknowledged_at !== null) {
            return back();
        }

        $action->update(['acknowledged_at' => Carbon::now()]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Marked as read.']);
    }
}
