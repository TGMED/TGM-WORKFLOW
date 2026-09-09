<?php

namespace Tests\Feature;

use App\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The migration that turned the free-text department column into a table.
 *
 * Worth its own test because the ordinary suite never sees it work: it runs on
 * an empty database, where a backfill has nothing to backfill. The risk this
 * covers is the one that only shows up on real data — two spellings of the same
 * department becoming two departments.
 */
class DepartmentBackfillTest extends TestCase
{
    /**
     * Rolled back by hand rather than by RefreshDatabase: this test drives the
     * migrator itself, so it cannot sit inside a transaction the migrator would
     * not see.
     */
    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh');

        parent::tearDown();
    }

    public function test_distinct_spellings_of_one_department_become_one_row(): void
    {
        $this->artisan('migrate:fresh');

        // Put the column back and fill it as the old shape would have.
        Schema::table('users', function ($table) {
            $table->string('department', 80)->nullable();
        });

        $ids = [];

        foreach (['Finance', 'finance', ' Finance ', 'Operations', null, ''] as $index => $name) {
            $ids[$index] = DB::table('users')->insertGetId([
                'name' => "Person {$index}",
                'email' => "person{$index}@example.test",
                'password' => 'x',
                'department' => $name,
                'is_active' => true,
                'employment_status' => 'confirmed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Re-run just the backfill, the way the migration does.
        $migration = require database_path('migrations/2026_09_09_000001_create_departments_and_teams_tables.php');

        $backfill = (function () {
            /** @var object $this */
            $this->backfillDepartments();
        })->bindTo($migration, $migration);

        DB::table('users')->update(['department_id' => null]);
        Department::query()->forceDelete();

        $backfill();

        $departments = Department::query()->orderBy('name')->get();

        // Finance in three spellings is one department, not three.
        $this->assertSame(['Finance', 'Operations'], $departments->pluck('name')->all());

        $finance = $departments->firstWhere('name', 'Finance');

        $this->assertSame(
            [$finance->id, $finance->id, $finance->id],
            DB::table('users')->whereIn('id', [$ids[0], $ids[1], $ids[2]])->pluck('department_id')->all(),
        );

        // A blank column leaves somebody in no department rather than in one
        // called "".
        $this->assertNull(DB::table('users')->where('id', $ids[4])->value('department_id'));
        $this->assertNull(DB::table('users')->where('id', $ids[5])->value('department_id'));
    }

    public function test_the_slug_stays_unique_across_departments(): void
    {
        $this->artisan('migrate:fresh');

        Department::query()->create(['name' => 'Finance', 'slug' => Department::uniqueSlug('Finance')]);
        $second = Department::query()->create(['name' => 'Finance team', 'slug' => Department::uniqueSlug('Finance')]);

        $this->assertSame('finance-2', $second->slug);
    }
}
