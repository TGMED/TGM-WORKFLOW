<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRelationRequest;
use App\Models\EmployeeRelation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Family tab: next of kin, dependants and family members. One controller
 * for all three, since the only thing that differs is the kind.
 */
class RelationController extends Controller
{
    public function store(StoreEmployeeRelationRequest $request): RedirectResponse
    {
        $relation = $request->user()->relations()->create($request->validated());

        return $this->done("{$relation->kind->label()} added.");
    }

    public function update(StoreEmployeeRelationRequest $request, EmployeeRelation $relation): RedirectResponse
    {
        $this->authorise($request, $relation);

        $relation->update($request->validated());

        return $this->done("{$relation->kind->label()} updated.");
    }

    public function destroy(Request $request, EmployeeRelation $relation): RedirectResponse
    {
        $this->authorise($request, $relation);

        $label = $relation->kind->label();
        $relation->delete();

        return $this->done("{$label} removed.");
    }

    /**
     * These are personal records, so only the person they belong to may touch
     * them. Route model binding will happily hand over anyone else's.
     */
    protected function authorise(Request $request, EmployeeRelation $relation): void
    {
        abort_unless($relation->user_id === $request->user()->id, 403);
    }

    protected function done(string $message): RedirectResponse
    {
        return back()->with('toast', ['type' => 'success', 'message' => $message]);
    }
}
