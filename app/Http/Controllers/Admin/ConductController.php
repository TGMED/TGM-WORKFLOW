<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmploymentStatus;
use App\Enums\StaffActionKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\IssueStaffActionRequest;
use App\Models\EmploymentSettings;
use App\Models\Offence;
use App\Models\StaffAction;
use App\Models\User;
use App\Services\ConductNotifier;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Queries, warnings and confirmations: issuing them, reading the answers, and
 * how long probation runs before a confirmation is due.
 */
class ConductController extends Controller
{
    public function __construct(protected ConductNotifier $notifier) {}

    public function index(Request $request): Response
    {
        $kind = StaffActionKind::tryFrom($request->string('kind')->toString());

        $actions = StaffAction::query()
            ->with(['subject:id,name,employee_id,department_id', 'subject.department:id,name', 'issuedBy:id,name', 'offence:id,code,title'])
            ->when($kind !== null, fn (Builder $q) => $q->where('kind', $kind->value))
            ->latest()
            ->paginate(PerPage::from($request, 25))
            ->withQueryString()
            ->through(fn (StaffAction $action): array => [
                'id' => $action->id,
                'subject' => $action->subject->name,
                'department' => $action->subject->department?->name,
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
                'state_label' => $action->stateLabel(),
                'state_tone' => $action->stateTone(),
                'created_at' => $action->created_at?->toIso8601String(),
            ]);

        $counts = StaffAction::query()
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        return Inertia::render('admin/Conduct', [
            'actions' => $actions,
            'filters' => ['kind' => $kind->value ?? ''],
            'kinds' => StaffActionKind::options(),
            'counts' => collect(StaffActionKind::cases())
                ->mapWithKeys(fn (StaffActionKind $case): array => [$case->value => (int) ($counts[$case->value] ?? 0)])
                ->all(),
            'people' => User::query()
                ->active()
                ->clocksIn()
                ->orderBy('name')
                ->get(['id', 'name', 'position', 'employment_status', 'hired_at', 'probation_months'])
                ->map(fn (User $person): array => [
                    'value' => $person->id,
                    'label' => $person->position === null ? $person->name : "{$person->name} · {$person->position}",
                    'on_probation' => $person->employment_status === EmploymentStatus::Probation,
                    'confirmation_due' => $person->employment_status === EmploymentStatus::Probation
                        ? $person->confirmationDueOn()?->format('j M Y')
                        : null,
                ])
                ->all(),
            'offences' => Offence::query()
                ->active()
                ->inRegisterOrder()
                ->get()
                ->map(fn (Offence $offence): array => [
                    'value' => $offence->id,
                    'label' => $offence->code === null ? $offence->title : "{$offence->code} · {$offence->title}",
                ])
                ->all(),
            'probation_months' => EmploymentSettings::probationMonths(),
        ]);
    }

    public function store(IssueStaffActionRequest $request): RedirectResponse
    {
        $kind = $request->enum('kind', StaffActionKind::class);
        $subject = User::query()->findOrFail($request->integer('subject_user_id'));

        $action = DB::transaction(function () use ($request, $kind, $subject): StaffAction {
            $action = StaffAction::query()->create([
                'subject_user_id' => $subject->id,
                'issued_by_id' => $request->user()->id,
                'kind' => $kind,
                'title' => $request->filled('title')
                    ? $request->string('title')->toString()
                    : 'Confirmation of appointment',
                'body' => $request->string('body')->toString(),
                'offence_id' => $request->input('offence_id'),
                'response_due_on' => $request->input('response_due_on'),
            ]);

            // The letter and the record move together, or not at all.
            if ($kind === StaffActionKind::Confirmation) {
                $subject->update([
                    'employment_status' => EmploymentStatus::Confirmed,
                    'confirmed_at' => Carbon::today(),
                ]);
            }

            return $action;
        });

        $this->notifier->issued($action);

        return back()->with('toast', [
            'type' => 'success',
            'message' => match ($kind) {
                StaffActionKind::Confirmation => "{$subject->name} is confirmed. They, their head of department and HR have been emailed.",
                default => "The {$kind->label()} is issued. {$subject->name}, their head of department and HR have been emailed.",
            },
        ]);
    }

    /**
     * The company's probation length. Somebody with their own length keeps it.
     */
    public function probation(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'probation_months' => ['required', 'integer', 'between:1,24'],
        ]);

        EmploymentSettings::current()->update($validated);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Probation now runs {$validated['probation_months']} months, except for anyone given their own length.",
        ]);
    }
}
