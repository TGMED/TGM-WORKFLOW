<?php

namespace App\Models;

use Database\Factories\EmployeeProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The HR record an employee keeps themselves.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $other_names
 * @property string|null $title
 * @property string|null $gender
 * @property Carbon|null $date_of_birth
 * @property string|null $place_of_birth
 * @property string|null $marital_status
 * @property string|null $mothers_maiden_name
 * @property string|null $avatar_path
 * @property string|null $attendance_id
 * @property string|null $spouse_name
 * @property string|null $spouse_phone
 * @property int|null $number_of_kids
 * @property string|null $blood_group
 * @property string|null $genotype
 * @property string|null $allergies
 * @property string|null $medical_history
 * @property string|null $religion
 * @property string|null $national_id_number
 * @property string|null $country_of_origin
 * @property string|null $state_of_origin
 * @property string|null $local_government
 * @property string|null $alternate_phone
 * @property string|null $alternate_email
 * @property string|null $bank_name
 * @property string|null $account_number
 * @property string|null $account_name
 * @property string|null $bvn
 * @property string|null $swift_code
 * @property string|null $sort_code
 * @property string|null $annual_rent
 * @property string|null $rsa_number
 * @property string|null $pfa_name
 * @property string|null $tax_identification_number
 * @property string|null $nhf_number
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'first_name',
    'last_name',
    'other_names',
    'title',
    'gender',
    'date_of_birth',
    'place_of_birth',
    'marital_status',
    'mothers_maiden_name',
    'avatar_path',
    'attendance_id',
    'spouse_name',
    'spouse_phone',
    'number_of_kids',
    'blood_group',
    'genotype',
    'allergies',
    'medical_history',
    'religion',
    'national_id_number',
    'country_of_origin',
    'state_of_origin',
    'local_government',
    'alternate_phone',
    'alternate_email',
    'bank_name',
    'account_number',
    'account_name',
    'bvn',
    'swift_code',
    'sort_code',
    'annual_rent',
    'rsa_number',
    'pfa_name',
    'tax_identification_number',
    'nhf_number',
    'completed_at',
])]
class EmployeeProfile extends Model
{
    /** @use HasFactory<EmployeeProfileFactory> */
    use HasFactory;

    /**
     * The fields someone has to fill in before the app will let them past the
     * profile page. Deliberately short: it is the set the people team cannot
     * run payroll or an emergency call-out without. Staff ID, bank and family
     * are chased separately and do not lock anyone out.
     *
     * Three more are required but live elsewhere: the phone number and the
     * joining date on users, and at least one address of their own. See
     * missingElsewhere().
     *
     * @var list<string>
     */
    public const REQUIRED = [
        'first_name',
        'last_name',
        'gender',
        'date_of_birth',
        'country_of_origin',
        'state_of_origin',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'number_of_kids' => 'integer',
            'annual_rent' => 'decimal:2',
            'completed_at' => 'datetime',
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
     * Every required field answered. Three of them are not columns on this
     * table: the primary phone and the joining date live on users, and an
     * address is a row of its own, so each is checked where it actually sits.
     */
    public function isComplete(): bool
    {
        foreach (self::REQUIRED as $field) {
            if (blank($this->{$field})) {
                return false;
            }
        }

        return $this->missingElsewhere() === [];
    }

    /**
     * The fields still outstanding, so the page can say what is missing rather
     * than just refusing to let go.
     *
     * @return array<int, string>
     */
    public function missingFields(): array
    {
        $missing = array_values(array_filter(
            self::REQUIRED,
            fn (string $field): bool => blank($this->{$field}),
        ));

        return [...$missing, ...$this->missingElsewhere()];
    }

    /**
     * The required details that are not columns on this table.
     *
     * @return array<int, string>
     */
    protected function missingElsewhere(): array
    {
        $missing = [];

        if (blank($this->user->phone)) {
            $missing[] = 'phone';
        }

        if (blank($this->user->hired_at)) {
            $missing[] = 'hired_at';
        }

        if (! $this->hasAddress()) {
            $missing[] = 'address';
        }

        return $missing;
    }

    /**
     * At least one address on file. Read off the loaded relation when the
     * caller already has it, since this runs on every gated request.
     */
    protected function hasAddress(): bool
    {
        return $this->user->relationLoaded('addresses')
            ? $this->user->addresses->isNotEmpty()
            : $this->user->addresses()->exists();
    }

    /**
     * The display name users.name should carry, built from the parts the
     * employee gave. Other names sit in the middle where they belong.
     */
    public function displayName(): ?string
    {
        $name = trim(implode(' ', array_filter([
            $this->first_name,
            $this->other_names,
            $this->last_name,
        ])));

        return $name === '' ? null : $name;
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null
            ? null
            : Storage::disk('public')->url($this->avatar_path);
    }
}
