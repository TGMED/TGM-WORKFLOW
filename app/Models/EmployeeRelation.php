<?php

namespace App\Models;

use App\Enums\RelationKind;
use Database\Factories\EmployeeRelationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone an employee is related to: next of kin, a dependant, or family
 * recorded for the record's sake.
 *
 * @property int $id
 * @property int $user_id
 * @property RelationKind $kind
 * @property string $name
 * @property string $relationship
 * @property string|null $phone
 * @property string|null $email
 * @property Carbon|null $date_of_birth
 * @property string|null $gender
 * @property string|null $occupation
 * @property string|null $address
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'kind',
    'name',
    'relationship',
    'phone',
    'email',
    'date_of_birth',
    'gender',
    'occupation',
    'address',
])]
class EmployeeRelation extends Model
{
    /** @use HasFactory<EmployeeRelationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RelationKind::class,
            'date_of_birth' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<EmployeeRelation>  $query
     */
    public function scopeOfKind(Builder $query, RelationKind $kind): void
    {
        $query->where('kind', $kind);
    }
}
