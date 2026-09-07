<?php

namespace App\Models;

use App\Enums\ReportCategory;
use App\Enums\ReportStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An incident raised by a member of staff.
 *
 * Deliberately not auditable, unlike almost everything else here. The audit
 * trail is a separate page behind a separate permission, and writing the
 * reporter's id into it would hand their identity to everyone who can read
 * the trail — which is exactly the set of people this record is kept from.
 * What an administrator did with a case is recorded on the row itself.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $subject_user_id
 * @property string|null $subject_name
 * @property ReportCategory $category
 * @property string $subject
 * @property string $body
 * @property Carbon|null $occurred_on
 * @property string|null $place
 * @property string|null $evidence_path
 * @property string|null $evidence_name
 * @property ReportStatus $status
 * @property int|null $handled_by_id
 * @property Carbon|null $handled_at
 * @property string|null $resolution_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $reporter
 * @property-read User|null $subjectUser
 * @property-read User|null $handledBy
 */
#[Fillable([
    'user_id',
    'subject_user_id',
    'subject_name',
    'category',
    'subject',
    'body',
    'occurred_on',
    'place',
    'evidence_path',
    'evidence_name',
    'status',
    'handled_by_id',
    'handled_at',
    'resolution_note',
])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ReportCategory::class,
            'status' => ReportStatus::class,
            'occurred_on' => 'date',
            'handled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The colleague the report is about, where one was picked from the staff
     * list rather than typed in by name.
     *
     * @return BelongsTo<User, $this>
     */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_id');
    }

    public function hasEvidence(): bool
    {
        return $this->evidence_path !== null;
    }

    /**
     * Who the report is against, however it was given. Null when the report
     * is about a situation rather than a person.
     */
    public function subjectLabel(): ?string
    {
        return $this->subjectUser->name ?? $this->subject_name;
    }

    /**
     * The opening of the account, for the case list.
     */
    public function excerpt(int $characters = 160): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', $this->body) ?? ''), $characters);
    }

    /**
     * Who may open the attachment: the person who filed it, and the
     * administrators who handle reports. Nobody else, and in particular not
     * the person the report is about.
     */
    public function evidenceVisibleTo(User $user): bool
    {
        return ($this->user_id !== null && $this->user_id === $user->id)
            || $user->canHandleReports();
    }

    /**
     * Cases still needing somebody's attention.
     *
     * @param  Builder<Report>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [
            ReportStatus::Submitted->value,
            ReportStatus::UnderReview->value,
        ]);
    }

    /**
     * Urgent categories first, then oldest first: a case that has been sitting
     * is the one most in need of being picked up.
     *
     * @param  Builder<Report>  $query
     */
    public function scopeInTriageOrder(Builder $query): void
    {
        $urgent = array_values(array_map(
            fn (ReportCategory $category): string => $category->value,
            array_filter(
                ReportCategory::cases(),
                fn (ReportCategory $category): bool => $category->isUrgent(),
            ),
        ));

        $query
            ->orderByRaw(
                'case when category in ('.implode(',', array_fill(0, count($urgent), '?')).') then 0 else 1 end',
                $urgent,
            )
            ->orderBy('created_at');
    }
}
