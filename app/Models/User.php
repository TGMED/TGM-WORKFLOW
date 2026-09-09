<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\ExitReason;
use App\Enums\Permission;
use App\Notifications\ResetPassword;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property int $id
 * @property string|null $employee_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property int|null $department_id
 * @property int|null $team_id
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
 * @property-read Department|null $department
 * @property-read Team|null $team
 * @property-read Department|null $headedDepartment
 * @property-read Team|null $ledTeam
 * @property-read Collection<int, Role> $roles
 * @property-read EmployeeProfile|null $profile
 * @property-read SalaryProfile|null $salaryProfile
 */
#[Fillable([
    'employee_id',
    'name',
    'email',
    'password',
    'phone',
    'department_id',
    'team_id',
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
            'hired_at' => 'date',
            'employment_status' => EmploymentStatus::class,
            'confirmed_at' => 'date',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'exit_reason' => ExitReason::class,
            'exit_date' => 'date',
            'location_id' => 'integer',
            'department_id' => 'integer',
            'team_id' => 'integer',
        ];
    }

    /**
     * Every role this person holds. A pivot rather than a column because the
     * roles are not mutually exclusive: leading a team is something somebody
     * does as well as their job, not instead of it.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
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
     * The part of the company this person sits in.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The group inside that department, when the department has been divided
     * into any. Most people have a department and no team.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The department this person heads, if they head one.
     *
     * @return HasOne<Department, $this>
     */
    public function headedDepartment(): HasOne
    {
        return $this->hasOne(Department::class, 'head_user_id');
    }

    /**
     * The team this person leads, if they lead one.
     *
     * @return HasOne<Team, $this>
     */
    public function ledTeam(): HasOne
    {
        return $this->hasOne(Team::class, 'lead_user_id');
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
        $this->loadMissing('roles');

        return $this->roles->contains(fn (Role $role): bool => in_array($role->slug, $slugs, true));
    }

    /**
     * The role to show when there is only room for one. Roles are seeded in
     * order of privilege, so the lowest id is the most senior one held.
     */
    public function primaryRole(): ?Role
    {
        $this->loadMissing('roles');

        return $this->roles->sortBy('id')->first();
    }

    /**
     * Everything this person may do, across every role they hold. Returned in
     * catalogue order so the roles page and the shared props read the same way
     * however the roles were granted.
     *
     * @return array<int, Permission>
     */
    public function permissions(): array
    {
        $this->loadMissing('roles.rolePermissions');

        $held = $this->roles
            ->flatMap(fn (Role $role): array => $role->permissions())
            ->unique()
            ->all();

        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => in_array($permission, $held, true),
        ));
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
     * Whether any role this person holds has been granted something. Super
     * admins hold the whole catalogue implicitly, so the system cannot be locked out of
     * itself by an unlucky edit on the roles page.
     */
    public function hasPermission(Permission $permission): bool
    {
        $this->loadMissing('roles.rolePermissions');

        return $this->roles->contains(
            fn (Role $role): bool => $role->hasPermission($permission),
        );
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
     * Everyone this person is responsible for: the department they head, and
     * the team they lead. Themselves excluded — nobody manages themselves, and
     * counting them would flatter every average on their own dashboard.
     *
     * One query, because both the approval routing and the dashboards read it
     * and neither wants a loop.
     *
     * @return array<int, int>
     */
    public function managedUserIds(): array
    {
        $this->loadMissing('headedDepartment', 'ledTeam');

        $department = $this->headedDepartment?->id;
        $team = $this->ledTeam?->id;

        if ($department === null && $team === null) {
            return [];
        }

        return self::query()
            ->active()
            ->whereKeyNot($this->id)
            ->where(fn (Builder $query) => $query
                ->when($department !== null, fn (Builder $q) => $q->orWhere('department_id', $department))
                ->when($team !== null, fn (Builder $q) => $q->orWhere('team_id', $team)))
            ->pluck('id')
            ->all();
    }

    /**
     * Whether this person runs a department or a team, and so has a group to
     * be shown on their own dashboard.
     */
    public function managesAnyone(): bool
    {
        $this->loadMissing('headedDepartment', 'ledTeam');

        return $this->headedDepartment !== null || $this->ledTeam !== null;
    }

    /**
     * Whether this person decides on requests for the whole company, or only
     * for the people they are responsible for.
     *
     * Heading a department or leading a team carries approval rights, but only
     * over that department or that team. Somebody who also holds approval
     * rights through any other role — an approver, an administrator — decides
     * company-wide as they always did. The test is where the right comes from,
     * not whether it is held.
     */
    public function approvesCompanyWide(): bool
    {
        $this->loadMissing('roles.rolePermissions');

        return $this->roles
            ->reject(fn (Role $role): bool => in_array($role->slug, Role::assignedThroughDepartments(), true))
            ->contains(fn (Role $role): bool => $role->hasPermission(Permission::ApproveRequests));
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
        $query->whereDoesntHave('roles', fn (Builder $q) => $q->where('slug', Role::SUPER_ADMIN));
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeWithRole(Builder $query, string ...$slugs): void
    {
        $query->whereHas('roles', fn (Builder $q) => $q->whereIn('slug', $slugs));
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
        $query->whereHas('roles', fn (Builder $q) => $q->whereKey(Role::idsWithPermission($permission)));
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

    /**
     * Send the reset link through our own notification rather than the
     * framework's, so it goes out on the queue like everything else.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }
}
