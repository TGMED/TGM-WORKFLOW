<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\ExitReason;
use App\Enums\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property int $id
 * @property string|null $employee_id
 * @property string $name
 * @property string $email
 * @property int $role_id
 * @property string|null $phone
 * @property string|null $department
 * @property string|null $position
 * @property Carbon|null $hired_at
 * @property EmploymentStatus $employment_status
 * @property Carbon|null $confirmed_at
 * @property bool $is_active
 * @property Carbon|null $deactivated_at
 * @property ExitReason|null $exit_reason
 * @property Carbon|null $exit_date
 * @property string|null $exit_note
 * @property int|null $location_id
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property string|null $whats_new_seen
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Location|null $location
 * @property-read Role $role
 * @property-read EmployeeProfile|null $profile
 * @property-read SalaryProfile|null $salaryProfile
 */
#[Fillable([
    'employee_id',
    'name',
    'email',
    'password',
    'role_id',
    'phone',
    'department',
    'position',
    'hired_at',
    'employment_status',
    'confirmed_at',
    'is_active',
    'deactivated_at',
    'exit_reason',
    'exit_date',
    'exit_note',
    'location_id',
    'whats_new_seen',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $appends = ['initials'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role_id' => 'integer',
            'hired_at' => 'date',
            'employment_status' => EmploymentStatus::class,
            'confirmed_at' => 'date',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'exit_reason' => ExitReason::class,
            'exit_date' => 'date',
            'location_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The site this person clocks in at.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * The HR record this person keeps themselves.
     *
     * @return HasOne<EmployeeProfile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    /**
     * Next of kin, dependants and family members, all three lists together.
     *
     * @return HasMany<EmployeeRelation, $this>
     */
    public function relations(): HasMany
    {
        return $this->hasMany(EmployeeRelation::class);
    }

    /**
     * @return HasMany<EmployeeAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(EmployeeAddress::class);
    }

    /**
     * What this person has asked not to be written to about.
     *
     * @return HasMany<NotificationSetting, $this>
     */
    public function notificationSettings(): HasMany
    {
        return $this->hasMany(NotificationSetting::class);
    }

    /**
     * Browsers this person has allowed notifications in.
     *
     * @return HasMany<PushToken, $this>
     */
    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * @return HasMany<ClockAttempt, $this>
     */
    public function clockAttempts(): HasMany
    {
        return $this->hasMany(ClockAttempt::class);
    }

    /**
     * What this person is paid. Separate from the HR profile because the HR
     * profile is theirs to edit and this is not.
     *
     * @return HasOne<SalaryProfile, $this>
     */
    public function salaryProfile(): HasOne
    {
        return $this->hasOne(SalaryProfile::class);
    }

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * @return HasMany<LeaveRequest, $this>
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * @return HasMany<LatenessRequest, $this>
     */
    public function latenessRequests(): HasMany
    {
        return $this->hasMany(LatenessRequest::class);
    }

    public function hasRole(string ...$slugs): bool
    {
        return in_array($this->role->slug, $slugs, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN);
    }

    public function isApprover(): bool
    {
        return $this->hasRole(Role::APPROVER);
    }

    /**
     * Whether this person's role has been granted something. Super admins hold
     * the whole catalogue implicitly, so the system cannot be locked out of
     * itself by an unlucky edit on the roles page.
     */
    public function hasPermission(Permission $permission): bool
    {
        $this->loadMissing('role.rolePermissions');

        return $this->role->hasPermission($permission);
    }

    /**
     * Anyone whose role may decide on requests. Which roles those are is set
     * on the roles page rather than fixed here, so a new role can be given the
     * approvals inbox without a deploy.
     */
    public function canApprove(): bool
    {
        return $this->hasPermission(Permission::ApproveRequests);
    }

    /**
     * Whether this person may read the reports desk. Kept beside canApprove()
     * as a named check rather than a permission test scattered through the
     * code, because it guards the one thing in the app that identifies a
     * reporter to somebody other than themselves.
     */
    public function canHandleReports(): bool
    {
        return $this->hasPermission(Permission::HandleReports);
    }

    /**
     * Whether this person sits at manager level or above, which the policy
     * uses to set the larger leave entitlement. The app has no separate grade
     * to read: holding approval rights is what being a manager here means.
     */
    public function isManagerOrAbove(): bool
    {
        return $this->canApprove();
    }

    /**
     * Whether probation has been passed. Somebody with no start date on file
     * is taken at their recorded status rather than guessed at.
     */
    public function isConfirmed(): bool
    {
        return $this->employment_status === EmploymentStatus::Confirmed;
    }

    /**
     * Whole months served, counted from the start date. Null when no start
     * date is on file, which leaves a service rule unenforceable rather than
     * shutting somebody out on a blank field.
     */
    public function serviceMonths(?Carbon $on = null): ?int
    {
        if ($this->hired_at === null) {
            return null;
        }

        return (int) $this->hired_at->diffInMonths($on ?? Carbon::now());
    }

    /**
     * Whether anyone has named this person as their relief officer on a leave
     * request still waiting for cover to be agreed. Staff without approval
     * rights reach the approvals page on the strength of this alone.
     */
    public function hasReliefDuties(): bool
    {
        return LeaveRequest::query()
            ->pending()
            ->where('relief_officer_id', $this->id)
            // A sign-off from a round since resubmitted is spent, so the
            // request is waiting on them again.
            ->whereDoesntHave(
                'approvals',
                fn (Builder $query) => $query->where('approver_id', $this->id)
                    ->whereColumn('approvals.round', 'leave_requests.round'),
            )
            ->exists();
    }

    /**
     * Whether the approvals page has anything to offer this person.
     */
    public function usesApprovals(): bool
    {
        return $this->canApprove() || $this->hasReliefDuties();
    }

    /**
     * Super admins administer the system rather than work a shift, so they
     * have no attendance of their own.
     */
    public function clocksIn(): bool
    {
        return ! $this->isSuperAdmin();
    }

    /**
     * The profile row, created empty on first read so callers never have to
     * juggle a null. Nothing is written until the employee saves something.
     */
    public function profileRecord(): EmployeeProfile
    {
        $profile = $this->profile ?? $this->profile()->make();

        // The completeness check reads the phone number off the user, so hand
        // the profile back the person it belongs to rather than let it go
        // looking for one it already has.
        $profile->setRelation('user', $this);

        return $profile;
    }

    /**
     * Super admins administer the system rather than appear on the payroll,
     * so there is no HR record for them to keep and nothing to gate them on.
     */
    public function needsProfile(): bool
    {
        return ! $this->isSuperAdmin();
    }

    /**
     * Whether this person may use the rest of the app. A profile that was
     * never started counts as incomplete.
     */
    public function hasCompleteProfile(): bool
    {
        if (! $this->needsProfile()) {
            return true;
        }

        return $this->profileRecord()->isComplete();
    }

    /**
     * Two-letter monogram used by the avatar component.
     */
    public function getInitialsAttribute(): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($this->name)) ?: []));

        $letters = array_map(
            fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)),
            array_slice($words, 0, 2),
        );

        return implode('', $letters) ?: '?';
    }

    /**
     * Everyone who punches a clock, which is everyone but the administrators.
     *
     * @param  Builder<User>  $query
     */
    public function scopeClocksIn(Builder $query): void
    {
        $query->whereHas('role', fn (Builder $q) => $q->where('slug', '!=', Role::SUPER_ADMIN));
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeWithRole(Builder $query, string ...$slugs): void
    {
        $query->whereHas('role', fn (Builder $q) => $q->whereIn('slug', $slugs));
    }

    /**
     * Everyone whose role holds a permission, super admins included. Used
     * wherever a list of possible approvers is needed, so the list follows the
     * roles page rather than a hard-coded pair of slugs.
     *
     * @param  Builder<User>  $query
     */
    public function scopeWithPermission(Builder $query, Permission $permission): void
    {
        $query->whereIn('role_id', Role::idsWithPermission($permission));
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Staff who have left. The counterpart to `active`, named for what it
     * means rather than for the flag being false.
     *
     * @param  Builder<User>  $query
     */
    public function scopeDeparted(Builder $query): void
    {
        $query->where('is_active', false);
    }

    /**
     * Whether this person has been taken through the exit flow, as opposed
     * to simply switched off. Accounts deactivated before exits were
     * recorded carry no reason, and are left alone rather than invented for.
     */
    public function hasExited(): bool
    {
        return ! $this->is_active && $this->exit_reason !== null;
    }

    /**
     * Their last working day, which is the exit date when one was recorded
     * and otherwise the day the account was switched off.
     */
    public function lastWorkingDay(): ?Carbon
    {
        return $this->exit_date ?? $this->deactivated_at?->copy()->startOfDay();
    }
}
