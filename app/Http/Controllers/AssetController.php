<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the company has handed to the person asking.
 */
class AssetController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Assets', [
            'assets' => Asset::query()
                ->with(['category:id,name', 'location:id,name'])
                ->where('assigned_user_id', $request->user()->id)
                ->orderBy('tag')
                ->get()
                ->map(fn (Asset $asset): array => [
                    'id' => $asset->id,
                    'tag' => $asset->tag,
                    'name' => $asset->name,
                    'category' => $asset->category->name,
                    'serial_number' => $asset->serial_number,
                    'location' => $asset->location?->name,
                    'spot' => $asset->spot,
                    'condition_label' => $asset->condition->label(),
                    'assigned_at' => $asset->assigned_at?->toIso8601String(),
                ])
                ->values(),
        ]);
    }
}
