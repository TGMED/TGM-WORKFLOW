<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\RecommendationStatus;
use App\Http\Requests\StoreTerminationRecommendationRequest;
use App\Models\Offence;
use App\Models\TerminationRecommendation;
use App\Models\User;
use App\Notifications\TerminationRecommended;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A senior member of staff putting it to the people team that somebody who
 * answers to them should be let go.
 *
 * Nothing here ends anybody's employment. It puts a case in front of the
 * people team, who answer it on their own page, and even an accepted case
 * leaves the exit to be recorded by hand with all the clearing up that goes
 * with it.
 */
class TerminationRecommendationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $mine = TerminationRecommendation::query()
            ->with(['subject:id,name,position', 'offence:id,code,title', 'decidedBy:id,name'])
            ->where('raised_by_id', $user->id)
            ->latest()
            ->limit(40)
            ->get();

        $people = User::query()
            ->active()
            ->whereKey($user->peopleAnsweringToMe())
            ->orderBy('name')
            ->get(['id', 'name', 'position'])
            ->map(fn (User $person): array => [
                'value' => $person->id,
                'label' => $person->position === null
                    ? $person->name
                    : "{$person->name} · {$person->position}",
            ])
            ->all();

        return Inertia::render('Recommendations', [
            'recommendations' => $mine->map(fn (TerminationRecommendation $row): array => [
                'id' => $row->id,
                'subject' => $row->subject->name,
                'offence' => $row->offence?->title,
                'occurrence' => $row->occurrence,
                'grounds' => $row->grounds,
                'status' => $row->status->value,
                'status_label' => $row->status->label(),
                'status_tone' => $row->status->tone(),
                'hr_note' => $row->hr_note,
                'decided_by' => $row->decidedBy?->name,
                'decided_at' => $row->decided_at?->toIso8601String(),
                'created_at' => $row->created_at?->toIso8601String(),
            ])->values(),
            'people' => $people,
            'offences' => $this->offenceOptions(),
        ]);
    }

    public function store(StoreTerminationRecommendationRequest $request): RedirectResponse
    {
        $recommendation = TerminationRecommendation::query()->create([
            'subject_user_id' => $request->integer('subject_user_id'),
            'raised_by_id' => $request->user()->id,
            'offence_id' => $request->input('offence_id'),
            'occurrence' => $request->integer('occurrence'),
            'grounds' => $request->string('grounds')->toString(),
            'status' => RecommendationStatus::Pending,
        ]);

        $recommendation->load(['subject', 'raisedBy', 'offence.sanctions']);

        Notification::send($this->peopleTeam(), new TerminationRecommended($recommendation));

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Your recommendation is with HR. Nothing changes on their record until HR acts on it.',
        ]);
    }

    /**
     * Take it back, while the people team has not answered it.
     */
    public function destroy(Request $request, TerminationRecommendation $recommendation): RedirectResponse
    {
        abort_unless($recommendation->raised_by_id === $request->user()->id, 403);

        if (! $recommendation->status->isOpen()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'HR has already answered that one.',
            ]);
        }

        $recommendation->update([
            'status' => RecommendationStatus::Withdrawn,
            'decided_at' => Carbon::now(),
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Your recommendation has been withdrawn.',
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    protected function peopleTeam(): Collection
    {
        return User::query()
            ->active()
            ->withPermission(Permission::ManageStaff)
            ->get();
    }

    /**
     * The register, with what each entry's ladder reaches, so somebody raising
     * a case can see whether the handbook backs it before they write it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function offenceOptions(): array
    {
        return Offence::query()
            ->active()
            ->with('sanctions')
            ->inRegisterOrder()
            ->get()
            ->map(fn (Offence $offence): array => [
                'value' => $offence->id,
                'label' => $offence->code === null
                    ? $offence->title
                    : "{$offence->code} · {$offence->title}",
                'ends_employment' => $offence->canEndEmployment(),
                'ladder' => $offence->sanctions
                    ->map(fn ($sanction): array => [
                        'occurrence' => $sanction->occurrence,
                        'occurrence_label' => $sanction->occurrenceLabel(),
                        'action_label' => $sanction->action->label(),
                        'ends_employment' => $sanction->action->endsEmployment(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();
    }
}
