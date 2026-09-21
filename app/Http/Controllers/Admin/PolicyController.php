<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PolicyCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\PolicyRequest;
use App\Models\Policy;
use App\Services\PolicyLibrary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The handbook and everything under it. Publishing a new version stands the
 * old one down; nothing here deletes what staff have been held to.
 */
class PolicyController extends Controller
{
    public function __construct(protected PolicyLibrary $library) {}

    public function index(): Response
    {
        $policies = Policy::query()
            ->with(['uploadedBy:id,name', 'supersedes:id,title,version'])
            ->orderByDesc('is_active')
            ->orderBy('category')
            ->orderByDesc('effective_from')
            ->get();

        return Inertia::render('admin/Policies', [
            'policies' => $policies->map(fn (Policy $policy): array => $this->payload($policy))->values(),
            'categories' => PolicyCategory::options(),
            'totals' => [
                'in_force' => $policies->filter(fn (Policy $p): bool => $p->isInForce())->count(),
                'upcoming' => $policies->filter(
                    fn (Policy $p): bool => $p->is_active && ! $p->isInForce(),
                )->count(),
                'retired' => $policies->where('is_active', false)->count(),
            ],
        ]);
    }

    /**
     * Open any version, retired ones included: the library lists them, and
     * whoever keeps it may need to read what staff used to be held to.
     */
    public function show(Policy $policy): StreamedResponse
    {
        return $this->library->open($policy);
    }

    public function store(PolicyRequest $request): RedirectResponse
    {
        $file = $request->file('document');

        $policy = $this->library->publish(
            $file,
            [
                ...$request->payload(),
                'uploaded_by_id' => $request->user()->id,
                'is_active' => true,
            ],
            $request->supersedes(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => $policy->supersedes_id === null
                ? "{$policy->title} is published."
                : "{$policy->title} is published, and the version it replaces has been retired.",
        ]);
    }

    /**
     * Correct the details of one already published. A new document is a new
     * version rather than an edit, so attaching one here replaces the file
     * only where the policy has not yet come into force.
     */
    public function update(PolicyRequest $request, Policy $policy): RedirectResponse
    {
        $policy->update($request->payload());

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$policy->title} has been updated.",
        ]);
    }

    /**
     * Take it out of force. The document stays readable to anyone looking
     * back at what the rule used to say.
     */
    public function retire(Policy $policy): RedirectResponse
    {
        $this->library->retire($policy);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$policy->title} is no longer in force. It stays on file.",
        ]);
    }

    /**
     * Put a retired policy back in force. For the case where the wrong one
     * was stood down.
     */
    public function restore(Policy $policy): RedirectResponse
    {
        $policy->update(['is_active' => true]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$policy->title} is back in force.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Policy $policy): array
    {
        return [
            'id' => $policy->id,
            'title' => $policy->title,
            'category' => $policy->category->value,
            'category_label' => $policy->category->label(),
            'version' => $policy->version,
            'summary' => $policy->summary,
            'file_name' => $policy->file_name,
            'size_label' => $policy->sizeLabel(),
            'effective_from' => $policy->effective_from->toDateString(),
            'effective_label' => $policy->effective_from->format('j M Y'),
            'in_force' => $policy->isInForce(),
            'is_active' => $policy->is_active,
            'is_upcoming' => $policy->is_active && $policy->effective_from->greaterThan(Carbon::now()->startOfDay()),
            'uploaded_by' => $policy->uploadedBy?->name,
            'supersedes' => $policy->supersedes === null ? null : [
                'id' => $policy->supersedes->id,
                'title' => $policy->supersedes->title,
                'version' => $policy->supersedes->version,
            ],
            'created_at' => $policy->created_at?->toIso8601String(),
        ];
    }
}
