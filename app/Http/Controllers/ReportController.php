<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\ReportCategory;
use App\Http\Requests\StoreReportRequest;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportEvidence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The reporting desk as staff see it: a form for raising something, and the
 * standing of what they have already raised.
 *
 * A reporter sees their own reports and nothing else. They are not shown the
 * handler's notes — those are the file, not the answer — only how far the
 * case has got.
 */
class ReportController extends Controller
{
    public function __construct(protected ReportEvidence $evidence) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $reports = Report::query()
            ->where('user_id', $user->id)
            ->with('subjectUser:id,name')
            ->latest()
            ->get()
            ->map(fn (Report $report): array => [
                'id' => $report->id,
                'category' => $report->category->value,
                'category_label' => $report->category->label(),
                'subject' => $report->subject,
                'body' => $report->body,
                'against' => $report->subjectLabel(),
                'occurred_on' => $report->occurred_on?->toDateString(),
                'place' => $report->place,
                'has_evidence' => $report->hasEvidence(),
                'status' => $report->status->value,
                // The reporter's wording, not the internal one.
                'status_label' => $report->status->reporterLabel(),
                'status_tone' => $report->status->tone(),
                'closed' => ! $report->status->isOpen(),
                'created_at' => $report->created_at?->toIso8601String(),
                'handled_at' => $report->handled_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Reports', [
            'reports' => $reports,
            'categories' => ReportCategory::options(),
            'colleagues' => $this->colleagues($user),
            'today' => now()->toDateString(),
            // How many people can read a report, shown on the form. A number
            // is worth more than a promise: it says exactly how far this goes.
            'handler_count' => User::query()
                ->active()
                ->withPermission(Permission::HandleReports)
                ->count(),
        ]);
    }

    public function store(StoreReportRequest $request): RedirectResponse
    {
        $report = Report::query()->create([
            ...$request->payload(),
            'user_id' => $request->user()->id,
        ]);

        if ($request->hasFile('evidence')) {
            $this->evidence->attach($report, $request->file('evidence'));
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Report received. Someone from the people team will look at it.',
        ]);
    }

    /**
     * Everyone a report could be about: active colleagues, minus the reporter.
     * Filed against a leaver by name instead, which the form allows for.
     *
     * @return array<int, array{value: int, label: string}>
     */
    protected function colleagues(User $user): array
    {
        return User::query()
            ->active()
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->with('department:id,name')
            ->get(['id', 'name', 'department_id'])
            ->map(fn (User $person): array => [
                'value' => $person->id,
                'label' => $person->department !== null
                    ? "{$person->name} · {$person->department->name}"
                    : $person->name,
            ])
            ->all();
    }
}
