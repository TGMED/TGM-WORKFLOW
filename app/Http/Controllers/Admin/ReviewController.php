<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReviewVisibility;
use App\Http\Controllers\Controller;
use App\Models\PerformanceReview;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every performance review, with who wrote it. The only page in the app that
 * names the author of a review to somebody other than the author, which is why
 * it answers to a permission of its own.
 */
class ReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $visibility = ReviewVisibility::tryFrom($request->string('visibility')->toString());

        $reviews = PerformanceReview::query()
            ->with([
                'subject:id,name,employee_id,department_id',
                'subject.department:id,name',
                'reviewer:id,name',
            ])
            ->when($visibility !== null, fn (Builder $q) => $q->where('visibility', $visibility->value))
            ->latest()
            ->paginate(PerPage::from($request, 25))
            ->withQueryString()
            ->through(fn (PerformanceReview $review): array => [
                'id' => $review->id,
                'subject' => $review->subject->name,
                'department' => $review->subject->department?->name,
                'reviewer' => $review->reviewer->name,
                'standing_label' => $review->standing->label(),
                'visibility' => $review->visibility->value,
                'visibility_label' => $review->visibility->label(),
                'visibility_tone' => $review->visibility->tone(),
                'rating' => $review->rating,
                'body' => $review->body,
                'created_at' => $review->created_at?->toIso8601String(),
            ]);

        $counts = PerformanceReview::query()
            ->selectRaw('visibility, count(*) as total')
            ->groupBy('visibility')
            ->pluck('total', 'visibility');

        return Inertia::render('admin/Reviews', [
            'reviews' => $reviews,
            'filters' => ['visibility' => $visibility->value ?? ''],
            'visibilities' => ReviewVisibility::options(),
            'counts' => [
                'public' => (int) ($counts[ReviewVisibility::Public->value] ?? 0),
                'private' => (int) ($counts[ReviewVisibility::Private->value] ?? 0),
            ],
        ]);
    }
}
