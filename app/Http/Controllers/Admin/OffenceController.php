<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OffenceSeverity;
use App\Enums\SanctionAction;
use App\Http\Controllers\Controller;
use App\Models\Offence;
use App\Models\Policy;
use App\Models\Sanction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The register of offences and the ladder against each.
 *
 * Behind the policy permission rather than one of its own: what counts as an
 * offence and what follows it is the policy, written out in a form the app can
 * read. Whoever publishes the handbook keeps this in step with it.
 */
class OffenceController extends Controller
{
    public function index(): Response
    {
        $offences = Offence::query()
            ->with(['sanctions', 'policy:id,title,version,is_active'])
            ->inRegisterOrder()
            ->get();

        return Inertia::render('admin/Offences', [
            'offences' => $offences->map(fn (Offence $offence): array => $this->payload($offence))->values(),
            'severities' => OffenceSeverity::options(),
            'actions' => SanctionAction::options(),
            // Only policies in force can be cited: an offence anchored to a
            // retired document is anchored to nothing anybody is held to.
            'policies' => Policy::query()
                ->active()
                ->orderBy('title')
                ->get(['id', 'title', 'version'])
                ->map(fn (Policy $policy): array => [
                    'value' => $policy->id,
                    'label' => $policy->version === null
                        ? $policy->title
                        : "{$policy->title} (v{$policy->version})",
                ])
                ->all(),
            'totals' => [
                'active' => $offences->where('is_active', true)->count(),
                // An entry nobody can trace back to a document is the thing
                // this page exists to stop, so it is counted in plain sight.
                'unanchored' => $offences->whereNull('policy_id')->count(),
                'without_ladder' => $offences
                    ->filter(fn (Offence $offence): bool => $offence->sanctions->isEmpty())
                    ->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $offence = Offence::query()->create($this->validated($request));

        $this->writeLadder($offence, $request);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$offence->title} is on the register.",
        ]);
    }

    public function update(Request $request, Offence $offence): RedirectResponse
    {
        $offence->update($this->validated($request, $offence));

        $this->writeLadder($offence, $request);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$offence->title} has been updated.",
        ]);
    }

    /**
     * Take an offence off the register without losing it. Cases already
     * decided under it keep pointing at it, so it is never deleted.
     */
    public function toggle(Offence $offence): RedirectResponse
    {
        $offence->update(['is_active' => ! $offence->is_active]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $offence->is_active
                ? "{$offence->title} is back on the register."
                : "{$offence->title} is off the register. Cases already decided under it are untouched.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Offence $offence = null): array
    {
        return $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('offences', 'code')->ignore($offence?->id)->whereNull('deleted_at'),
            ],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'severity' => ['required', Rule::enum(OffenceSeverity::class)],
            'policy_id' => [
                'nullable',
                'integer',
                Rule::exists('policies', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'ladder' => ['present', 'array', 'max:6'],
            'ladder.*.occurrence' => ['required', 'integer', 'between:1,6'],
            'ladder.*.action' => ['required', Rule::enum(SanctionAction::class)],
            'ladder.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /**
     * Rewrite the ladder wholesale.
     *
     * Simpler than reconciling rung by rung, and safe because a rung carries
     * nothing but what the policy says: the decisions taken under it live on
     * the cases, not here.
     *
     * @throws \Throwable
     */
    protected function writeLadder(Offence $offence, Request $request): void
    {
        /** @var array<int, array<string, mixed>> $ladder */
        $ladder = $request->input('ladder', []);

        DB::transaction(function () use ($offence, $ladder): void {
            $offence->sanctions()->forceDelete();

            $seen = [];

            foreach ($ladder as $rung) {
                $occurrence = (int) $rung['occurrence'];

                // Two rungs for the same occurrence would be two answers to
                // one question; the first one written wins.
                if (in_array($occurrence, $seen, true)) {
                    continue;
                }

                $seen[] = $occurrence;

                $offence->sanctions()->create([
                    'occurrence' => $occurrence,
                    'action' => $rung['action'],
                    'notes' => $rung['notes'] ?? null,
                ]);
            }
        });

        $offence->load('sanctions');
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Offence $offence): array
    {
        return [
            'id' => $offence->id,
            'code' => $offence->code,
            'title' => $offence->title,
            'description' => $offence->description,
            'severity' => $offence->severity->value,
            'severity_label' => $offence->severity->label(),
            'severity_tone' => $offence->severity->tone(),
            'is_active' => $offence->is_active,
            'policy' => $offence->policy === null ? null : [
                'id' => $offence->policy->id,
                'title' => $offence->policy->title,
                'version' => $offence->policy->version,
                'is_active' => $offence->policy->is_active,
            ],
            'ends_employment' => $offence->canEndEmployment(),
            'ladder' => $offence->sanctions
                ->map(fn (Sanction $sanction): array => [
                    'id' => $sanction->id,
                    'occurrence' => $sanction->occurrence,
                    'occurrence_label' => $sanction->occurrenceLabel(),
                    'action' => $sanction->action->value,
                    'action_label' => $sanction->action->label(),
                    'action_tone' => $sanction->action->tone(),
                    'ends_employment' => $sanction->action->endsEmployment(),
                    'notes' => $sanction->notes,
                ])
                ->values()
                ->all(),
        ];
    }
}
