<?php

namespace App\Models;

use App\Enums\OffenceSeverity;
use App\Enums\SanctionAction;
use Database\Factories\OffenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One entry in the register of offences, with the ladder of what follows it.
 *
 * Anchored to the policy document it comes from, so a sanction can always be
 * traced back to the paragraph that justifies it.
 *
 * @property int $id
 * @property string|null $code
 * @property string $title
 * @property string|null $description
 * @property OffenceSeverity $severity
 * @property int|null $policy_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Policy|null $policy
 * @property-read Collection<int, Sanction> $sanctions
 */
#[Fillable(['code', 'title', 'description', 'severity', 'policy_id', 'is_active'])]
class Offence extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<OffenceFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => OffenceSeverity::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Policy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    /**
     * @return HasMany<Sanction, $this>
     */
    public function sanctions(): HasMany
    {
        return $this->hasMany(Sanction::class)->orderBy('occurrence');
    }

    /**
     * @param  Builder<Offence>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * What the policy says follows the nth time somebody does this.
     *
     * Past the end of the ladder the last rung stands: a register that stops
     * at the third occurrence is not saying the fourth is free, it is saying
     * nothing harsher is written down.
     */
    public function sanctionFor(int $occurrence): ?Sanction
    {
        $ladder = $this->sanctions;

        if ($ladder->isEmpty()) {
            return null;
        }

        return $ladder->firstWhere('occurrence', $occurrence) ?? $ladder->last();
    }

    /**
     * Whether the ladder reaches dismissal at any rung, which is what makes
     * an offence one a termination can be recommended on.
     */
    public function canEndEmployment(): bool
    {
        return $this->sanctions->contains(
            fn (Sanction $sanction): bool => $sanction->action === SanctionAction::Dismissal,
        );
    }

    /**
     * The register reads worst-first, and alphabetically inside that.
     *
     * @param  Builder<Offence>  $query
     */
    public function scopeInRegisterOrder(Builder $query): void
    {
        $query->orderByRaw("case severity when 'gross' then 1 when 'serious' then 2 else 3 end")
            ->orderBy('title');
    }
}
