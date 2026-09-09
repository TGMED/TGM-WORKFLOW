<?php

namespace App\Models;

use App\Enums\Permission;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * What a person may do. Roles live in the database so new ones can be added
 * without a deploy, but the three below are wired into middleware and are
 * looked up by slug.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property-read Collection<int, RolePermission> $rolePermissions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['slug', 'name', 'description', 'is_system'])]
class Role extends Model implements AuditableContract
{
    use Auditable;
    use SoftDeletes;

    public const SUPER_ADMIN = 'super_admin';

    public const APPROVER = 'approver';

    public const STAFF = 'staff';

    public const HEAD_OF_DEPARTMENT = 'head_of_department';

    public const TEAM_LEAD = 'team_lead';

    /**
     * The two roles that come with people attached. Neither may be granted
     * from the staff form: they are set on the departments page, where naming
     * somebody a head or a lead also names who they are responsible for.
     *
     * @return array<int, string>
     */
    public static function assignedThroughDepartments(): array
    {
        return [self::HEAD_OF_DEPARTMENT, self::TEAM_LEAD];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @return HasMany<RolePermission, $this>
     */
    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * Everything this role may do. Super admins run the system and hold the
     * whole catalogue implicitly: a stored list would only be a list somebody
     * could accidentally take away from, locking the last administrator out.
     *
     * @return array<int, Permission>
     */
    public function permissions(): array
    {
        if ($this->slug === self::SUPER_ADMIN) {
            return Permission::cases();
        }

        $held = $this->rolePermissions
            ->map(fn (RolePermission $grant): Permission => $grant->permission)
            ->all();

        // Returned in catalogue order rather than whatever order the rows came
        // back in, so the roles page and the shared props read the same way
        // every time.
        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => in_array($permission, $held, true),
        ));
    }

    public function hasPermission(Permission $permission): bool
    {
        return $this->slug === self::SUPER_ADMIN
            || in_array($permission, $this->permissions(), true);
    }

    /**
     * The database used to clear a role's grants for us, on the cascade behind
     * the foreign key. A soft-deleted role never reaches that cascade, so the
     * grants are taken down and brought back with it here instead.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $role): void {
            $role->rolePermissions()->delete();
        });

        static::restored(function (self $role): void {
            $role->rolePermissions()->onlyTrashed()->restore();
        });
    }

    /**
     * Replace this role's grants with exactly the list given. Super admins are
     * left alone: their permissions are not stored, so there is nothing here
     * to set, and writing rows would imply they could be removed.
     *
     * @param  array<int, Permission|string>  $permissions
     */
    public function syncPermissions(array $permissions): void
    {
        if ($this->slug === self::SUPER_ADMIN) {
            return;
        }

        $wanted = collect($permissions)
            ->map(fn (Permission|string $permission): string => $permission instanceof Permission
                ? $permission->value
                : $permission)
            ->unique()
            ->values();

        // A grant carries nothing but the pair, so replacing the set wholesale
        // is both simpler and cheaper than working out the difference. Forced,
        // because a soft-deleted grant keeps its slot in the unique index on
        // (role_id, permission) and re-granting it would collide.
        $this->rolePermissions()->forceDelete();

        $this->rolePermissions()->insert(
            $wanted->map(fn (string $permission): array => [
                'role_id' => $this->id,
                'permission' => $permission,
            ])->all(),
        );

        $this->unsetRelation('rolePermissions');
    }

    /**
     * Ids of every role holding a permission, super admins included. Handed to
     * `whereIn` rather than a `whereHas`, since the set is tiny and the query
     * it guards is often already doing enough work.
     *
     * @return array<int, int>
     */
    public static function idsWithPermission(Permission $permission): array
    {
        return self::query()
            ->where(fn ($query) => $query
                ->where('slug', self::SUPER_ADMIN)
                ->orWhereHas('rolePermissions', fn ($grants) => $grants
                    ->where('permission', $permission->value)))
            ->pluck('id')
            ->all();
    }

    public static function findBySlug(string $slug): self
    {
        return self::query()->where('slug', $slug)->firstOrFail();
    }

    public static function idFor(string $slug): int
    {
        return (int) self::query()->where('slug', $slug)->value('id');
    }

    /**
     * Roles travel to the browser by slug, so a form posts back something
     * stable rather than an id that differs between environments.
     *
     * @return array<int, array{value: string, label: string, description: string|null}>
     */
    public static function options(): array
    {
        return self::query()
            ->orderBy('id')
            ->get()
            ->map(fn (self $role): array => [
                'value' => $role->slug,
                'label' => $role->name,
                'description' => $role->description,
            ])
            ->all();
    }

    /**
     * The roles the staff form may hand out. Heads of department and team
     * leads are missing on purpose: they come with people attached, and the
     * departments page is where those people get named.
     *
     * @return array<int, array{value: string, label: string, description: string|null}>
     */
    public static function grantableOptions(): array
    {
        return array_values(array_filter(
            self::options(),
            fn (array $option): bool => ! in_array($option['value'], self::assignedThroughDepartments(), true),
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return self::query()->pluck('slug')->all();
    }
}
