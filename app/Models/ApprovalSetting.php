<?php

namespace App\Models;

use App\Enums\RequestModule;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * How many approvals a module's requests need before they are granted.
 *
 * @property int $id
 * @property RequestModule $module
 * @property int $approvers_required
 * @property int|null $escalation_hours
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['module', 'approvers_required', 'escalation_hours'])]
class ApprovalSetting extends Model implements AuditableContract
{
    use Auditable;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'module' => RequestModule::class,
            'approvers_required' => 'integer',
            'escalation_hours' => 'integer',
        ];
    }

    public static function for(RequestModule $module): self
    {
        return self::query()->firstOrCreate(
            ['module' => $module->value],
            ['approvers_required' => 1],
        );
    }

    public static function approversRequired(RequestModule $module): int
    {
        return max(1, self::for($module)->approvers_required);
    }

    /**
     * How long a request of this module may sit undecided before the people
     * team and the approver's own manager are told, or null where that module
     * does not escalate at all.
     */
    public static function escalationHours(RequestModule $module): ?int
    {
        $hours = self::for($module)->escalation_hours;

        return $hours === null || $hours < 1 ? null : $hours;
    }
}
