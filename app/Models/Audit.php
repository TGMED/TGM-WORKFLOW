<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Models\Audit as BaseAudit;

/**
 * One recorded change. Extends the package's model rather than using it
 * directly, so the columns it writes are declared where the rest of the app
 * can see them, and so the trail has somewhere to keep its own queries.
 *
 * @property int $id
 * @property string|null $user_type
 * @property int|null $user_id
 * @property string $event
 * @property string|null $auditable_type
 * @property string|int|null $auditable_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $url
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $tags
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class Audit extends BaseAudit
{
    /**
     * The person who made the change, or null when it was a console command
     * or a scheduled job. Declared here with a return type: the package builds
     * the same relation dynamically, which nothing static can follow.
     *
     * @return MorphTo<covariant Model, $this>
     */
    public function user(): MorphTo
    {
        $prefix = (string) config('audit.user.morph_prefix', 'user');

        return $this->morphTo(__FUNCTION__, $prefix.'_type', $prefix.'_id');
    }

    /**
     * Newest first, and by id within a second so a burst of changes made in
     * the same request still reads in the order it happened.
     *
     * @param  Builder<Audit>  $query
     */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->latest('created_at')->latest('id');
    }
}
