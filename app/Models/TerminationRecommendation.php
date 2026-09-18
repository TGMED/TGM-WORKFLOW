<?php

namespace App\Models;

use App\Enums\RecommendationStatus;
use Database\Factories\TerminationRecommendationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One recommendation that somebody be let go, put to the people team.
 *
 * @property int $id
 * @property int $subject_user_id
 * @property int $raised_by_id
 * @property int|null $offence_id
 * @property int|null $report_id
 * @property string $grounds
 * @property int $occurrence
 * @property RecommendationStatus $status
 * @property string|null $hr_note
 * @property int|null $decided_by_id
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $subject
 * @property-read User $raisedBy
 * @property-read Offence|null $offence
 * @property-read Report|null $report
 * @property-read User|null $decidedBy
 */
#[Fillable([
    'subject_user_id',
    'raised_by_id',
    'offence_id',
    'report_id',
    'grounds',
    'occurrence',
    'status',
    'hr_note',
    'decided_by_id',
    'decided_at',
])]
class TerminationRecommendation extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<TerminationRecommendationFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrence' => 'integer',
            'status' => RecommendationStatus::class,
            'decided_at' => 'datetime',
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
    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_id');
    }

    /**
     * @return BelongsTo<Offence, $this>
     */
    public function offence(): BelongsTo
    {
        return $this->belongsTo(Offence::class);
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    /**
     * @param  Builder<TerminationRecommendation>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', RecommendationStatus::Pending->value);
    }

    /**
     * What the policy says should follow, where the case rests on an offence
     * with a ladder written against it. The people team is not bound by it;
     * they are simply told what the handbook they published says.
     */
    public function policySanction(): ?Sanction
    {
        return $this->offence?->loadMissing('sanctions')->sanctionFor($this->occurrence);
    }

    /**
     * Whether the policy itself reaches dismissal at this occurrence. Where it
     * does not, the recommendation is asking for more than the handbook says,
     * which is worth putting in front of whoever decides it.
     */
    public function policyAgrees(): bool
    {
        return $this->policySanction()?->action->endsEmployment() ?? false;
    }
}
