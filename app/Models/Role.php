<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'name', 'description', 'is_system'])]
class Role extends Model
{
    public const SUPER_ADMIN = 'super_admin';

    public const APPROVER = 'approver';

    public const STAFF = 'staff';

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
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return self::query()->pluck('slug')->all();
    }
}
