<?php

namespace App\Http\Controllers;

use App\Enums\PolicyCategory;
use App\Models\Policy;
use App\Services\PolicyLibrary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The policies staff are held to, as staff read them. Everybody sees the same
 * list: a rule nobody can look up is not a rule anybody can follow.
 */
class PolicyController extends Controller
{
    public function index(): Response
    {
        $policies = Policy::query()
            ->inForce()
            ->orderBy('category')
            ->orderBy('title')
            ->get();

        return Inertia::render('Policies', [
            'groups' => collect(PolicyCategory::cases())
                ->map(fn (PolicyCategory $category): array => [
                    'value' => $category->value,
                    'label' => $category->label(),
                    'description' => $category->description(),
                    'policies' => $policies
                        ->where('category', $category)
                        ->map(fn (Policy $policy): array => [
                            'id' => $policy->id,
                            'title' => $policy->title,
                            'version' => $policy->version,
                            'summary' => $policy->summary,
                            'file_name' => $policy->file_name,
                            'size_label' => $policy->sizeLabel(),
                            'effective_label' => $policy->effective_from->format('j M Y'),
                        ])
                        ->values()
                        ->all(),
                ])
                ->filter(fn (array $group): bool => $group['policies'] !== [])
                ->values()
                ->all(),
            'total' => $policies->count(),
        ]);
    }

    /**
     * Hand back the document itself. Retired versions are not served here:
     * somebody reading the handbook should be reading the rule in force.
     */
    public function download(Request $request, Policy $policy): StreamedResponse
    {
        abort_unless($policy->isInForce(), 404);

        $disk = Storage::disk(PolicyLibrary::DISK);

        abort_unless($disk->exists($policy->file_path), 404);

        return $disk->download($policy->file_path, $policy->file_name);
    }
}
