<?php

namespace App\Http\Controllers;

use App\Enums\ReviewVisibility;
use App\Http\Requests\StoreReviewRequest;
use App\Models\PerformanceReview;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff reviewing each other's work.
 *
 * Somebody reads the public reviews about themselves with no name, and no
 * standing, on them. The standing would give the author away: every team has
 * one lead. Reviews from somebody with no working tie to the subject are
 * accepted, but only HR ever reads them.
 */
class ReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $aboutMe = PerformanceReview::query()
            ->where('subject_user_id', $user->id)
            ->public()
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (PerformanceReview $review): array => [
                'id' => $review->id,
                'rating' => $review->rating,
                'body' => $review->body,
                'created_at' => $review->created_at?->toIso8601String(),
            ])
            ->values();

        $written = PerformanceReview::query()
            ->with('subject:id,name,position')
            ->where('reviewer_id', $user->id)
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (PerformanceReview $review): array => [
                'id' => $review->id,
                'subject' => $review->subject->name,
                'rating' => $review->rating,
                'body' => $review->body,
                'visibility' => $review->visibility->value,
                'visibility_label' => $review->visibility->label(),
                'visibility_tone' => $review->visibility->tone(),
                'created_at' => $review->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Reviews', [
            'about_me' => $aboutMe,
            'written' => $written,
            'people' => $this->people($user),
        ]);
    }

    public function store(StoreReviewRequest $request): RedirectResponse
    {
        $reviewer = $request->user();
        $subject = User::query()->findOrFail($request->integer('subject_user_id'));

        $standing = PerformanceReview::standingOf($reviewer, $subject);

        $visibility = $standing->mayBePublic()
            ? $request->enum('visibility', ReviewVisibility::class)
            : ReviewVisibility::Private;

        PerformanceReview::query()->create([
            'subject_user_id' => $subject->id,
            'reviewer_id' => $reviewer->id,
            'standing' => $standing,
            'visibility' => $visibility,
            'rating' => $request->integer('rating'),
            'body' => $request->string('body')->toString(),
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $visibility === ReviewVisibility::Public
                ? "Your review is saved. {$subject->name} can read it, but not who wrote it."
                : 'Your review is saved. Only HR can read it.',
        ]);
    }

    /**
     * Everyone who may be reviewed, with whether a review from this person
     * could be shared with them. The ones it could are listed first, since
     * they are the people somebody actually works with.
     *
     * @return array<int, array{value: int, label: string, may_be_public: bool}>
     */
    protected function people(User $reviewer): array
    {
        return User::query()
            ->active()
            ->clocksIn()
            ->whereKeyNot($reviewer->id)
            ->orderBy('name')
            ->get(['id', 'name', 'position', 'department_id', 'team_id', 'manager_id'])
            ->map(fn (User $person): array => [
                'value' => $person->id,
                'label' => $person->position === null
                    ? $person->name
                    : "{$person->name} · {$person->position}",
                'may_be_public' => PerformanceReview::standingOf($reviewer, $person)->mayBePublic(),
            ])
            ->sortByDesc('may_be_public')
            ->values()
            ->all();
    }
}
