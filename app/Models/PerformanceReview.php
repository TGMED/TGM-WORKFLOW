<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\ReviewStanding;
use App\Enums\ReviewVisibility;
use Database\Factories\PerformanceReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One member of staff's review of another's work.
 *
 * Deliberately not auditable, like Report. The subject of a public review
 * reads it without the author's name, and the audit trail would hand that
 * name to everyone who can read the trail.
 *
 * @property int $id
 * @property int $subject_user_id
 * @property int $reviewer_id
 * @property ReviewStanding $standing
 * @property ReviewVisibility $visibility
 * @property int $rating
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $subject
 * @property-read User $reviewer
 */
#[Fillable(['subject_user_id', 'reviewer_id', 'standing', 'visibility', 'rating', 'body'])]
class PerformanceReview extends Model
{
    /** @use HasFactory<PerformanceReviewFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'standing' => ReviewStanding::class,
            'visibility' => ReviewVisibility::class,
            'rating' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @param  Builder<PerformanceReview>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->where('visibility', ReviewVisibility::Public->value);
    }

    /**
     * Where the reviewer stands to the subject today. Management is checked
     * first, so somebody who heads a department and leads a team in it writes
     * as management to all of it.
     */
    public static function standingOf(User $reviewer, User $subject): ReviewStanding
    {
        $reviewer->loadMissing('headedDepartment', 'ledTeam');

        $manages = $subject->manager_id === $reviewer->id
            || ($subject->department_id !== null && $reviewer->headedDepartment?->id === $subject->department_id)
            || $reviewer->hasPermission(Permission::ManageStaff);

        if ($manages) {
            return ReviewStanding::Management;
        }

        if ($subject->team_id !== null && $reviewer->ledTeam?->id === $subject->team_id) {
            return ReviewStanding::Lead;
        }

        // Colleagues are the people somebody works beside: their team, or
        // their department where they sit in no team.
        $colleagues = $subject->team_id !== null
            ? $reviewer->team_id === $subject->team_id
            : $subject->department_id !== null && $reviewer->department_id === $subject->department_id;

        return $colleagues ? ReviewStanding::Peer : ReviewStanding::Outside;
    }
}
