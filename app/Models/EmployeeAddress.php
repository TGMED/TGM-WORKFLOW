<?php

namespace App\Models;

use App\Support\Countries;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An address an employee keeps on file.
 *
 * @property int $id
 * @property int $user_id
 * @property string $label
 * @property string $street
 * @property string|null $city
 * @property string|null $state
 * @property string|null $country
 * @property string|null $postal_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'label',
    'street',
    'city',
    'state',
    'country',
    'postal_code',
])]
class EmployeeAddress extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The address on one line, for the summary card.
     */
    public function oneLine(): string
    {
        return implode(', ', array_filter([
            $this->street,
            $this->city,
            $this->state,
            $this->postal_code,
            Countries::name($this->country),
        ]));
    }
}
