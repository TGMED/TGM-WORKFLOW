<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RecommendationStatus;
use App\Http\Controllers\Controller;
use App\Models\TerminationRecommendation;
use App\Notifications\RecommendationDecided;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the people team answers recommendations to terminate.
 *
 * Accepting one does not end anybody's employment. It records that the people
 * team agrees, and the exit is still recorded on the staff page afterwards,
 * where the clearing up happens. Two hands, deliberately.
 */
class RecommendationDeskController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'pending';

        $recommendations = TerminationRecommendation::query()
            ->with([
                'subject:id,name,employee_id,position,department_id,hired_at',
                'subject.department:id,name',
                'raisedBy:id,name,position',
                'offence.sanctions',
                'offence.policy:id,title,version',
                'decidedBy:id,name',
            ])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()
            ->limit(100)
            ->get();

        $counts = TerminationRecommendation::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('admin/Recommendations', [
            'recommendations' => $recommendations
                ->map(fn (TerminationRecommendation $row): array => $this->payload($row))
                ->values(),
            'filters' => ['status' => $status],
            'statuses' => RecommendationStatus::options(),
            'counts' => [
                'pending' => (int) $counts->get(RecommendationStatus::Pending->value, 0),
                'accepted' => (int) $counts->get(RecommendationStatus::Accepted->value, 0),
                'declined' => (int) $counts->get(RecommendationStatus::Declined->value, 0),
            ],
        ]);
    }

    /**
     * Answer one. A note is asked for either way: the person who raised it has
     * to be able to tell their own team something, and a case declined without
     * a word is how the same case comes back next month unchanged.
     */
    public function update(Request $request, TerminationRecommendation $recommendation): RedirectResponse
    {
        if (! $recommendation->status->isOpen()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'That one has already been answered.',
            ]);
        }

        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    RecommendationStatus::Accepted->value,
                    RecommendationStatus::Declined->value,
                ]),
            ],
            'hr_note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $recommendation->update([
            'status' => $validated['status'],
            'hr_note' => $validated['hr_note'],
            'decided_by_id' => $request->user()->id,
            'decided_at' => Carbon::now(),
        ]);

        $recommendation->refresh()->load(['subject', 'decidedBy', 'raisedBy']);

        $recommendation->raisedBy->notify(new RecommendationDecided($recommendation));

        return back()->with('toast', [
            'type' => 'success',
            'message' => $recommendation->status === RecommendationStatus::Accepted
                ? 'Accepted. Record the exit on their staff page when the process is done.'
                : 'Declined, and the person who raised it has been told.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(TerminationRecommendation $row): array
    {
        $sanction = $row->policySanction();

        return [
            'id' => $row->id,
            'subject' => [
                'id' => $row->subject->id,
                'name' => $row->subject->name,
                'employee_id' => $row->subject->employee_id,
                'position' => $row->subject->position,
                'department' => $row->subject->department?->name,
                'hired_at' => $row->subject->hired_at?->toDateString(),
            ],
            'raised_by' => [
                'name' => $row->raisedBy->name,
                'position' => $row->raisedBy->position,
            ],
            'offence' => $row->offence === null ? null : [
                'id' => $row->offence->id,
                'code' => $row->offence->code,
                'title' => $row->offence->title,
                'severity_label' => $row->offence->severity->label(),
                'severity_tone' => $row->offence->severity->tone(),
                'policy' => $row->offence->policy?->title,
            ],
            'occurrence' => $row->occurrence,
            // What the handbook says follows this, and whether it reaches as
            // far as the recommendation is asking.
            'policy_says' => $sanction === null ? null : $sanction->action->label(),
            'policy_agrees' => $row->policyAgrees(),
            'grounds' => $row->grounds,
            'status' => $row->status->value,
            'status_label' => $row->status->label(),
            'status_tone' => $row->status->tone(),
            'hr_note' => $row->hr_note,
            'decided_by' => $row->decidedBy?->name,
            'decided_at' => $row->decided_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
