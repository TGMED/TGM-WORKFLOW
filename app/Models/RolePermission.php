<?php

namespace App\Models;

use App\Enums\Permission;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One thing one role may do. A row per grant, so the roles page can hand a
 * permission over or take it back without rewriting a blob, and so a grant
 * can be read back in a join rather than a loop.
 *
 * @property int $id
 * @property int $role_id
 * @property Permission $permission
 * @property Carbon|null $deleted_at
 * @property-read Role $role
 */
#[Fillable(['role_id', 'permission'])]
class RolePermission extends Model implements AuditableContract
{
    use Auditable;
    use SoftDeletes;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permission' => Permission::class,
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
