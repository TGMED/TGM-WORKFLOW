<?php

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * How the company is arranged.
 *
 * A department was a free-text field on the user until now, which meant there
 * was nothing to hang a head of department off, no way to be sure two people
 * typed the same word, and no list to count against. It becomes a table, with
 * teams inside it and a named person responsible for each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();

            // Nullable and cleared rather than cascaded: a department outlives
            // whoever happens to be heading it, and losing the head should
            // never take the department and its people with it.
            $table->foreignId('head_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'name']);
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('lead_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // A department names each of its teams once.
            $table->unique(['department_id', 'name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('phone')->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
        });

        $this->backfillDepartments();
        $this->seedRoles();

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('department');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('department', 80)->nullable()->after('phone');
        });

        // The names go back into the text column, so nothing is lost on the
        // way down even though the structure is.
        $names = DB::table('departments')->pluck('name', 'id');

        foreach ($names as $id => $name) {
            DB::table('users')->where('department_id', $id)->update(['department' => $name]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::dropIfExists('teams');
        Schema::dropIfExists('departments');

        DB::table('roles')->whereIn('slug', [Role::HEAD_OF_DEPARTMENT, Role::TEAM_LEAD])->delete();
    }

    /**
     * One department per distinct name already typed into the old column.
     *
     * Matched case-insensitively and on trimmed text, so "Finance" and
     * " finance" become one department rather than two. Whichever spelling
     * appears first is the one kept.
     */
    protected function backfillDepartments(): void
    {
        $seen = [];

        DB::table('users')
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->orderBy('id')
            ->select('id', 'department')
            ->chunkById(500, function ($users) use (&$seen): void {
                foreach ($users as $user) {
                    $name = trim((string) $user->department);

                    if ($name === '') {
                        continue;
                    }

                    $key = mb_strtolower($name);

                    $seen[$key] ??= DB::table('departments')->insertGetId([
                        'name' => $name,
                        'slug' => $this->uniqueSlug($name),
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('users')->where('id', $user->id)->update(['department_id' => $seen[$key]]);
                }
            });
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'department';
        $slug = $base;
        $suffix = 2;

        while (DB::table('departments')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * The two roles that come with people attached. Both are system roles:
     * the departments page relies on them by slug, so neither may be deleted,
     * though what each may do stays editable on the roles page.
     */
    protected function seedRoles(): void
    {
        $roles = [
            [
                'slug' => Role::HEAD_OF_DEPARTMENT,
                'name' => 'Head of department',
                'description' => 'Runs a department: sees how its own people are doing, and decides on their requests. Carries no company-wide sight of anything.',
                // Deliberately not the admin dashboard or the attendance
                // report: both are company-wide, and a head of department is
                // trusted with their own department, not with everybody. Their
                // numbers reach them through their own dashboard instead,
                // which is scoped to the people they are responsible for.
                'permissions' => [
                    Permission::ApproveRequests,
                ],
            ],
            [
                'slug' => Role::TEAM_LEAD,
                'name' => 'Team lead',
                'description' => 'Runs a team inside a department: sees how its people are doing and decides on their requests first.',
                'permissions' => [
                    Permission::ApproveRequests,
                ],
            ],
        ];

        foreach ($roles as $role) {
            if (DB::table('roles')->where('slug', $role['slug'])->exists()) {
                continue;
            }

            $id = DB::table('roles')->insertGetId([
                'slug' => $role['slug'],
                'name' => $role['name'],
                'description' => $role['description'],
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('role_permissions')->insert(array_map(
                fn (Permission $permission): array => [
                    'role_id' => $id,
                    'permission' => $permission->value,
                ],
                $role['permissions'],
            ));
        }
    }
};
