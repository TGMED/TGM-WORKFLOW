<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReportCategory;
use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateReportRequest;
use App\Models\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The reports desk. This is the only page in the app that puts a reporter's
 * name in front of somebody other than themselves, which is why it answers to
 * a permission of its own rather than riding along with staff management.
 */
class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'open';
        $category = $request->string('category')->toString();

        $reports = Report::query()
            ->with([
                'reporter:id,name,employee_id,department',
                'subjectUser:id,name,employee_id,department',
                'handledBy:id,name',
            ])
            ->when($status === 'open', fn (Builder $q) => $q->open())
            ->when(
                $status !== 'open' && $status !== 'all',
                fn (Builder $q) => $q->where('status', $status),
            )
            ->when($category !== '', fn (Builder $q) => $q->where('category', $category))
            // Open cases are worked through in triage order; a closed list is
            // a record, and reads better newest first.
            ->when(
                $status === 'open',
                fn (Builder $q) => $q->inTriageOrder(),
                fn (Builder $q) => $q->latest(),
            )
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Report $report): array => $this->payload($report));

        $counts = Report::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('admin/Reports', [
            'reports' => $reports,
            'filters' => [
                'status' => $status,
                'category' => $category ?: '',
            ],
            'statuses' => ReportStatus::options(),
            'categories' => ReportCategory::options(),
            'counts' => [
                'open' => (int) $counts->get(ReportStatus::Submitted->value, 0)
                    + (int) $counts->get(ReportStatus::UnderReview->value, 0),
                'submitted' => (int) $counts->get(ReportStatus::Submitted->value, 0),
                'under_review' => (int) $counts->get(ReportStatus::UnderReview->value, 0),
                'resolved' => (int) $counts->get(ReportStatus::Resolved->value, 0),
                'dismissed' => (int) $counts->get(ReportStatus::Dismissed->value, 0),
            ],
        ]);
    }

    /**
     * Move a case along. Whoever touches it last owns it, so the handler is
     * stamped on every change rather than only on the first.
     */
    public function update(UpdateReportRequest $request, Report $report): RedirectResponse
    {
        $status = ReportStatus::from($request->string('status')->toString());

        $report->update([
            'status' => $status,
            'resolution_note' => $request->string('resolution_note')->trim()->toString() ?: null,
            'handled_by_id' => $request->user()->id,
            // Stamped only when the case closes: the point of the timestamp is
            // how long staff waited for an answer, not when it was opened.
            'handled_at' => $status->isOpen() ? null : now(),
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Report marked {$status->label()}.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Report $report): array
    {
        return [
            'id' => $report->id,
            'category' => $report->category->value,
            'category_label' => $report->category->label(),
            'urgent' => $report->category->isUrgent(),
            'subject' => $report->subject,
            'body' => $report->body,
            'excerpt' => $report->excerpt(),
            'reporter' => $report->reporter === null ? null : [
                'id' => $report->reporter->id,
                'name' => $report->reporter->name,
                'employee_id' => $report->reporter->employee_id,
                'department' => $report->reporter->department,
            ],
            'against' => $report->subjectLabel(),
            'against_user_id' => $report->subject_user_id,
            'occurred_on' => $report->occurred_on?->toDateString(),
            'place' => $report->place,
            'has_evidence' => $report->hasEvidence(),
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            'status_tone' => $report->status->tone(),
            'open' => $report->status->isOpen(),
            'handled_by' => $report->handledBy?->name,
            'handled_at' => $report->handled_at?->toIso8601String(),
            'resolution_note' => $report->resolution_note,
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }
}
