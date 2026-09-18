<?php

namespace App\Models;

use App\Enums\PolicyCategory;
use Database\Factories\PolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One published policy document, at one version.
 *
 * Audited, because the answer to "what did the rule say at the time" is worth
 * more than the file itself.
 *
 * @property int $id
 * @property string $title
 * @property PolicyCategory $category
 * @property string|null $version
 * @property string|null $summary
 * @property string $file_path
 * @property string $file_name
 * @property int $file_size
 * @property string|null $mime_type
 * @property Carbon $effective_from
 * @property bool $is_active
 * @property int|null $uploaded_by_id
 * @property int|null $supersedes_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $uploadedBy
 * @property-read Policy|null $supersedes
 * @property-read Policy|null $supersededBy
 */
#[Fillable([
    'title',
    'category',
    'version',
    'summary',
    'file_path',
    'file_name',
    'file_size',
    'mime_type',
    'effective_from',
    'is_active',
    'uploaded_by_id',
    'supersedes_id',
])]
class Policy extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<PolicyFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'policies';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => PolicyCategory::class,
            'effective_from' => 'date',
            'is_active' => 'boolean',
            'file_size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /**
     * @return BelongsTo<Policy, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * @return HasOne<Policy, $this>
     */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    /**
     * @param  Builder<Policy>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Policies in force today. One dated ahead is written and waiting, and is
     * not yet the rule anybody is held to.
     *
     * @param  Builder<Policy>  $query
     */
    public function scopeInForce(Builder $query): void
    {
        $query->active()->where('effective_from', '<=', Carbon::now()->toDateString());
    }

    public function isInForce(): bool
    {
        return $this->is_active
            && $this->effective_from->lessThanOrEqualTo(Carbon::now()->startOfDay());
    }

    /**
     * The size as somebody would say it, so a person can tell a two-page memo
     * from a scanned handbook before they open it on their phone.
     */
    public function sizeLabel(): string
    {
        if ($this->file_size < 1024) {
            return $this->file_size.' B';
        }

        if ($this->file_size < 1024 * 1024) {
            return round($this->file_size / 1024).' KB';
        }

        return round($this->file_size / (1024 * 1024), 1).' MB';
    }
}
