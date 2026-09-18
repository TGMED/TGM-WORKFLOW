<?php

namespace App\Models;

use App\Enums\SanctionAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One rung of an offence's ladder: what follows the nth occurrence.
 *
 * @property int $id
 * @property int $offence_id
 * @property int $occurrence
 * @property SanctionAction $action
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Offence $offence
 */
#[Fillable(['offence_id', 'occurrence', 'action', 'notes'])]
class Sanction extends Model implements AuditableContract
{
    use Auditable;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrence' => 'integer',
            'action' => SanctionAction::class,
        ];
    }

    /**
     * @return BelongsTo<Offence, $this>
     */
    public function offence(): BelongsTo
    {
        return $this->belongsTo(Offence::class);
    }

    /**
     * "First time", "second time", and so on, since that reads better on a
     * page than a bare number.
     */
    public function occurrenceLabel(): string
    {
        return match ($this->occurrence) {
            1 => 'First time',
            2 => 'Second time',
            3 => 'Third time',
            default => $this->occurrence.'th time',
        };
    }
}
