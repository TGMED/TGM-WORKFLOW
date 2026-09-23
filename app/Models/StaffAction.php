<?php

namespace App\Models;

use App\Enums\StaffActionKind;
use Database\Factories\StaffActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A query, warning or confirmation issued to one member of staff.
 *
 * @property int $id
 * @property int $subject_user_id
 * @property int|null $issued_by_id
 * @property StaffActionKind $kind
 * @property string $title
 * @property string $body
 * @property int|null $offence_id
 * @property Carbon|null $response_due_on
 * @property string|null $response
 * @property Carbon|null $responded_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $subject
 * @property-read User|null $issuedBy
 * @property-read Offence|null $offence
 */
#[Fillable([
    'subject_user_id',
    'issued_by_id',
    'kind',
    'title',
    'body',
    'offence_id',
    'response_due_on',
    'response',
    'responded_at',
    'acknowledged_at',
])]
class StaffAction extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<StaffActionFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => StaffActionKind::class,
            'response_due_on' => 'date',
            'responded_at' => 'datetime',
            'acknowledged_at' => 'datetime',
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
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    /**
     * @return BelongsTo<Offence, $this>
     */
    public function offence(): BelongsTo
    {
        return $this->belongsTo(Offence::class);
    }

    public function awaitsResponse(): bool
    {
        return $this->kind->expectsResponse() && $this->responded_at === null;
    }

    /**
     * Where it stands, in a word or two, for the lists on both sides.
     */
    public function stateLabel(): string
    {
        if ($this->kind->expectsResponse()) {
            return match (true) {
                $this->responded_at !== null => 'Answered',
                $this->response_due_on !== null && $this->response_due_on->isPast() && ! $this->response_due_on->isToday() => 'Answer overdue',
                default => 'Awaiting answer',
            };
        }

        return $this->acknowledged_at !== null ? 'Acknowledged' : 'Not yet read';
    }

    public function stateTone(): string
    {
        return match ($this->stateLabel()) {
            'Answered', 'Acknowledged' => 'signal',
            'Answer overdue' => 'alert',
            default => 'brass',
        };
    }
}
