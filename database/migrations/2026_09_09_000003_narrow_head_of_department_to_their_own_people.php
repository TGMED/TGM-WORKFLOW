<?php

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Take company-wide sight back off heads of department.
 *
 * They were first seeded with the admin dashboard and the attendance report,
 * which show the whole company. That was wrong: a head of department is
 * responsible for their own department, and the numbers they need reach them
 * through their own dashboard, already scoped to their people.
 *
 * Written as its own migration because the seeding one has already run
 * somewhere. On a database where it has not, this finds nothing and does
 * nothing, so both paths end up in the same place.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const COMPANY_WIDE = [
        'admin.dashboard',
        'attendance.report',
    ];

    public function up(): void
    {
        $role = DB::table('roles')->where('slug', Role::HEAD_OF_DEPARTMENT)->value('id');

        if ($role === null) {
            return;
        }

        // Forced rather than soft-deleted: a soft-deleted grant keeps its slot
        // in the unique index on (role_id, permission), which would stop the
        // permission ever being granted again by hand from the roles page.
        DB::table('role_permissions')
            ->where('role_id', $role)
            ->whereIn('permission', self::COMPANY_WIDE)
            ->delete();
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('slug', Role::HEAD_OF_DEPARTMENT)->value('id');

        if ($role === null) {
            return;
        }

        foreach (self::COMPANY_WIDE as $permission) {
            $exists = DB::table('role_permissions')
                ->where('role_id', $role)
                ->where('permission', $permission)
                ->exists();

            if (! $exists) {
                DB::table('role_permissions')->insert([
                    'role_id' => $role,
                    'permission' => Permission::from($permission)->value,
                ]);
            }
        }
    }
};
