<?php

namespace App\Imports;

use App\Imports\Contracts\Importer;
use App\Imports\Importers\AttendanceImporter;
use App\Imports\Importers\EmployeeAddressImporter;
use App\Imports\Importers\EmployeeProfileImporter;
use App\Imports\Importers\EmployeeRelationImporter;
use App\Imports\Importers\LeaveRecordImporter;
use App\Imports\Importers\LeaveTypeImporter;
use App\Imports\Importers\LocationImporter;
use App\Imports\Importers\RestrictedPeriodImporter;
use App\Imports\Importers\RoleImporter;
use App\Imports\Importers\SalaryImporter;
use App\Imports\Importers\StaffImporter;
use App\Models\User;

/**
 * Every importable part of the system, in the order a company setting itself
 * up should work through them: the things other things point at first, then
 * the people, then their records, then their history.
 *
 * The order is the list. A new import is added by writing the class and
 * naming it here — everything else, the page, the routes, the template, the
 * reference and the permission check, follows from the importer itself.
 */
final class ImportRegistry
{
    /**
     * @var array<int, class-string<Importer>>
     */
    private const IMPORTERS = [
        LocationImporter::class,
        RoleImporter::class,
        LeaveTypeImporter::class,
        RestrictedPeriodImporter::class,
        StaffImporter::class,
        EmployeeProfileImporter::class,
        EmployeeAddressImporter::class,
        EmployeeRelationImporter::class,
        SalaryImporter::class,
        AttendanceImporter::class,
        LeaveRecordImporter::class,
    ];

    /**
     * @return array<int, Importer>
     */
    public function all(): array
    {
        return array_map(
            fn (string $class): Importer => app($class),
            self::IMPORTERS,
        );
    }

    /**
     * The imports one person may actually run. Each one answers to the same
     * permission that guards editing those records by hand, so this is never
     * a way round a page somebody cannot reach.
     *
     * @return array<int, Importer>
     */
    public function availableTo(User $user): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (Importer $importer): bool => $user->hasPermission($importer->permission()),
        ));
    }

    public function find(string $key): ?Importer
    {
        foreach ($this->all() as $importer) {
            if ($importer->key() === $key) {
                return $importer;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_map(fn (Importer $importer): string => $importer->key(), $this->all());
    }
}
