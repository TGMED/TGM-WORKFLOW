<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AssetCondition;
use App\Enums\AssetStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssetRequest;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\Location;
use App\Models\User;
use App\Services\AssetCustody;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The asset register: what the company owns, where it is kept, who has it.
 */
class AssetController extends Controller
{
    public function __construct(protected AssetCustody $custody) {}

    public function index(Request $request): Response
    {
        $status = AssetStatus::tryFrom($request->string('status')->toString());
        $category = $request->integer('category') ?: null;
        $search = trim($request->string('search')->toString());

        $assets = Asset::query()
            ->with(['category:id,name', 'location:id,name', 'assignee:id,name', 'assignments' => fn ($q) => $q->with('user:id,name')->latest('assigned_at')->limit(10)])
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status->value))
            ->when($category !== null, fn (Builder $q) => $q->where('asset_category_id', $category))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('tag', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('serial_number', 'like', "%{$search}%")))
            ->orderBy('tag')
            ->paginate(PerPage::from($request, 25))
            ->withQueryString()
            ->through(fn (Asset $asset): array => [
                'id' => $asset->id,
                'tag' => $asset->tag,
                'name' => $asset->name,
                'asset_category_id' => $asset->asset_category_id,
                'category' => $asset->category->name,
                'serial_number' => $asset->serial_number,
                'location_id' => $asset->location_id,
                'location' => $asset->location?->name,
                'spot' => $asset->spot,
                'status' => $asset->status->value,
                'status_label' => $asset->status->label(),
                'status_tone' => $asset->status->tone(),
                'condition' => $asset->condition->value,
                'condition_label' => $asset->condition->label(),
                'purchased_on' => $asset->purchased_on?->toDateString(),
                'purchase_cost' => $asset->purchase_cost,
                'notes' => $asset->notes,
                'assignee' => $asset->assignee?->name,
                'assigned_at' => $asset->assigned_at?->toIso8601String(),
                'history' => $asset->assignments->map(fn (AssetAssignment $spell): array => [
                    'user' => $spell->user->name,
                    'assigned_at' => $spell->assigned_at->toIso8601String(),
                    'returned_at' => $spell->returned_at?->toIso8601String(),
                    'note' => $spell->note,
                ])->values()->all(),
            ]);

        $counts = Asset::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('admin/Assets', [
            'assets' => $assets,
            'filters' => [
                'status' => $status->value ?? '',
                'category' => $category,
                'search' => $search,
            ],
            'counts' => collect(AssetStatus::cases())
                ->mapWithKeys(fn (AssetStatus $case): array => [$case->value => (int) ($counts[$case->value] ?? 0)])
                ->all(),
            'categories' => AssetCategory::query()
                ->withCount('assets')
                ->orderBy('name')
                ->get()
                ->map(fn (AssetCategory $c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'description' => $c->description,
                    'assets_count' => (int) $c->getAttribute('assets_count'),
                ])
                ->all(),
            'locations' => Location::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Location $l): array => ['value' => $l->id, 'label' => $l->name])->all(),
            'people' => User::query()->active()->clocksIn()->orderBy('name')->get(['id', 'name', 'position'])
                ->map(fn (User $u): array => [
                    'value' => $u->id,
                    'label' => $u->position === null ? $u->name : "{$u->name} · {$u->position}",
                ])->all(),
            'statuses' => array_values(array_filter(
                AssetStatus::options(),
                fn (array $option): bool => $option['value'] !== AssetStatus::Assigned->value,
            )),
            'all_statuses' => AssetStatus::options(),
            'conditions' => AssetCondition::options(),
        ]);
    }

    public function store(AssetRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $assigneeId = $data['assigned_user_id'] ?? null;
        unset($data['assigned_user_id']);

        $to = $assigneeId === null ? null : User::query()->findOrFail((int) $assigneeId);

        $asset = DB::transaction(function () use ($data, $to, $request): Asset {
            $asset = Asset::query()->create($data);

            if ($to !== null) {
                $this->custody->assign($asset, $to, $request->user());
            }

            return $asset;
        });

        return back()->with('toast', ['type' => 'success', 'message' => $to === null
            ? "{$asset->tag} is on the register."
            : "{$asset->tag} is on the register and with {$to->name}."]);
    }

    public function update(AssetRequest $request, Asset $asset): RedirectResponse
    {
        $data = $request->validated();

        // The status on the form never says "assigned", so an asset somebody
        // holds keeps that status unless it is being taken out of use, which
        // takes it back from them first.
        if ($asset->assigned_user_id !== null) {
            $next = AssetStatus::from($data['status']);

            if ($next === AssetStatus::Available) {
                unset($data['status']);
            } else {
                $this->custody->takeBack($asset, $next);
            }
        }

        $asset->update($data);

        return back()->with('toast', ['type' => 'success', 'message' => "{$asset->tag} has been updated."]);
    }

    public function assign(Request $request, Asset $asset): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($asset->status === AssetStatus::Retired) {
            return back()->with('toast', ['type' => 'error', 'message' => 'A retired asset cannot be handed out.']);
        }

        $to = User::query()->findOrFail((int) $validated['user_id']);

        $this->custody->assign($asset, $to, $request->user(), $validated['note'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => "{$asset->tag} is now with {$to->name}."]);
    }

    public function unassign(Asset $asset): RedirectResponse
    {
        if ($asset->assigned_user_id === null) {
            return back();
        }

        $this->custody->takeBack($asset);

        return back()->with('toast', ['type' => 'success', 'message' => "{$asset->tag} is back and available."]);
    }
}
