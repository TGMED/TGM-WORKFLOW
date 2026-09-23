<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        AssetCategory::query()->create($this->validated($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Category added.']);
    }

    public function update(Request $request, AssetCategory $category): RedirectResponse
    {
        $category->update($this->validated($request, $category));

        return back()->with('toast', ['type' => 'success', 'message' => 'Category updated.']);
    }

    /**
     * Only an empty category goes. Anything filed under it would be left
     * belonging to nothing.
     */
    public function destroy(AssetCategory $category): RedirectResponse
    {
        if ($category->assets()->exists()) {
            return back()->with('toast', ['type' => 'error', 'message' => 'Move its assets to another category first.']);
        }

        $category->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Category removed.']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?AssetCategory $category = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('asset_categories', 'name')->whereNull('deleted_at')->ignore($category?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
