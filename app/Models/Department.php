<?php

namespace App\Models;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A part of the company, with somebody responsible for it.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int|null $head_user_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $head
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Team> $teams
 */
#[Fillable(['name', 'slug', 'description', 'head_user_id', 'is_active'])]
class Department extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'head_user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * @param  Builder<Department>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * A name the URL and the imports can carry, unique across departments.
     * A soft-deleted department still holds its slot in the unique index, so
     * the suffix walks past those too.
     */
    public static function uniqueSlug(string $name, ?int $ignore = null): string
    {
        $base = Str::slug($name) ?: 'department';
        $slug = $base;
        $suffix = 2;

        while (self::withTrashed()
            ->where('slug', $slug)
            ->when($ignore !== null, fn (Builder $query) => $query->whereKeyNot($ignore))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public static function options(): array
    {
        return self::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (self $department): array => [
                'value' => $department->id,
                'label' => $department->name,
            ])
            ->all();
    }
}
