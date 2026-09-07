<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\EmploymentStatus;
use App\Enums\Permission;
use App\Enums\RelationKind;
use App\Enums\RequestStatus;
use App\Imports\ImportRegistry;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\LeaveRestrictedPeriod;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Role;
use App\Models\SalaryProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Importing records in bulk.
 *
 * The two things worth pinning down are that a check writes nothing and that
 * an import can never reach past the permissions the person already holds.
 * Everything else here is one importer's own reading of a row.
 */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * Somebody on a role of their own, holding exactly what is passed.
     *
     * @param  array<int, Permission>  $permissions
     */
    private function staffWith(array $permissions): User
    {
        $role = Role::query()->create([
            'slug' => 'custom_'.Role::query()->count(),
            'name' => 'Department head',
            'is_system' => false,
        ]);

        $role->syncPermissions($permissions);

        return User::factory()->create([
            'role_id' => $role->id,
            'location_id' => Location::factory()->create()->id,
        ]);
    }

    /**
     * @param  array<int, array<int, string|int|null>>  $rows
     * @param  array<int, string>  $headers
     */
    private function csv(array $headers, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        $handle = fopen($path, 'w');

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|null>>  $rows
     * @param  array<string, mixed>  $extra
     */
    private function upload(
        User $as,
        string $import,
        array $headers,
        array $rows,
        bool $commit = true,
        array $extra = [],
    ): TestResponse {
        return $this->actingAs($as)->post("/admin/imports/{$import}", [
            'file' => $this->csv($headers, $rows),
            'duplicates' => 'update',
            'commit' => $commit,
            ...$extra,
        ]);
    }

    // Reaching the pages at all.

    public function test_the_import_pages_need_the_import_permission(): void
    {
        $this->actingAs($this->staffWith([Permission::ManageStaff]))
            ->get('/admin/imports')
            ->assertForbidden();
    }

    public function test_somebody_only_sees_the_sheets_their_other_permissions_cover(): void
    {
        $this->actingAs($this->staffWith([Permission::ImportData, Permission::ManageStaff]))
            ->get('/admin/imports')
            ->assertInertia(fn ($page) => $page
                ->component('admin/Imports')
                ->where('imports', fn ($imports): bool => collect($imports)
                    ->pluck('key')
                    ->sort()
                    ->values()
                    ->all() === ['employee-addresses', 'employee-profiles', 'employee-relations', 'staff']));
    }

    /**
     * The point of the second gate: holding the import permission must never
     * widen what somebody may change, only how much of it at once.
     */
    public function test_an_import_cannot_reach_past_the_permission_that_guards_the_page(): void
    {
        $importer = $this->staffWith([Permission::ImportData, Permission::ManageStaff]);

        $this->actingAs($importer)->get('/admin/imports/salaries')->assertForbidden();
        $this->actingAs($importer)->get('/admin/imports/salaries/template')->assertForbidden();

        $this->upload($importer, 'salaries', ['email', 'annual_gross'], [['a@b.com', '100']])
            ->assertForbidden();
    }

    public function test_a_sheet_page_carries_its_own_column_reference(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/imports/salaries')
            ->assertInertia(fn ($page) => $page
                ->component('admin/Import')
                ->where('sheet.key', 'salaries')
                ->where('sheet.depends_on', ['staff'])
                ->has('sheet.notes')
                ->has('sheet.columns', 6)
                ->has('duplicate_options', 3)
                ->where('result', null));
    }

    public function test_an_unknown_sheet_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/imports/nothing-of-the-sort')
            ->assertNotFound();
    }

    // The template and the reference.

    public function test_every_sheet_serves_a_template_and_a_reference(): void
    {
        $admin = $this->admin();

        foreach (app(ImportRegistry::class)->keys() as $key) {
            $template = $this->actingAs($admin)->get("/admin/imports/{$key}/template");
            $template->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

            $this->actingAs($admin)->get("/admin/imports/{$key}/reference")->assertOk();
        }
    }

    /**
     * The template has to be a file the importer would actually accept, or it
     * is worse than no template at all.
     */
    public function test_the_staff_template_imports_as_it_stands(): void
    {
        Location::factory()->create(['name' => 'TGM Ikeja']);

        $csv = $this->actingAs($this->admin())
            ->get('/admin/imports/staff/template')
            ->streamedContent();

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($this->admin())
            ->post('/admin/imports/staff', [
                'file' => new UploadedFile($path, 'staff.csv', 'text/csv', null, true),
                'duplicates' => 'update',
                'commit' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'ada.eze@example.com',
            'employee_id' => 'TGM-0148',
        ]);
    }

    // Checking versus importing.

    public function test_checking_a_file_writes_nothing(): void
    {
        Location::factory()->create(['name' => 'TGM Ikeja']);

        $this->upload(
            $this->admin(),
            'staff',
            ['email', 'name', 'location'],
            [['new@example.com', 'New Person', 'TGM Ikeja']],
            commit: false,
        )->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
        $this->assertSame(1, session('import_result')['created']);
        $this->assertFalse(session('import_result')['committed']);
    }

    public function test_a_committed_file_is_written(): void
    {
        Location::factory()->create(['name' => 'TGM Ikeja']);

        $this->upload(
            $this->admin(),
            'staff',
            ['email', 'name', 'location'],
            [['new@example.com', 'New Person', 'TGM Ikeja']],
        )->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'name' => 'New Person']);
        $this->assertTrue(session('import_result')['committed']);
    }

    /**
     * One bad row must not cost the file. The rows are independent of each
     * other, and the operator has already been shown what would happen.
     */
    public function test_a_bad_row_is_reported_and_the_others_still_import(): void
    {
        Location::factory()->create(['name' => 'TGM Ikeja']);

        $this->upload($this->admin(), 'staff', ['email', 'name', 'location'], [
            ['good@example.com', 'Good Row', 'TGM Ikeja'],
            ['not-an-email', 'Bad Row', 'TGM Ikeja'],
            ['also-good@example.com', 'Another Good Row', 'TGM Ikeja'],
        ]);

        $this->assertDatabaseHas('users', ['email' => 'good@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'also-good@example.com']);

        $result = session('import_result');

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['failed']);
        // The header is line 1, so the second data row is line 3.
        $this->assertSame(3, $result['failures'][0]['row']);
    }

    public function test_a_file_with_none_of_the_expected_columns_is_turned_away(): void
    {
        $this->upload($this->admin(), 'staff', ['colour', 'shape'], [['red', 'square']])
            ->assertSessionHasErrors('file');
    }

    /**
     * A file with the right shape but a required value missing is reported
     * row by row rather than refused outright: a file carrying only an email
     * and a department is a correction, not a mistake, and refusing it up
     * front would make bulk corrections impossible.
     */
    public function test_a_row_missing_a_required_value_is_rejected_on_its_own(): void
    {
        $this->upload($this->admin(), 'staff', ['email'], [['brand-new@example.com']]);

        $this->assertSame(1, session('import_result')['failed']);
        $this->assertDatabaseMissing('users', ['email' => 'brand-new@example.com']);
    }

    // How duplicates are handled.

    public function test_update_writes_over_the_record_that_is_there(): void
    {
        $staff = User::factory()->create([
            'email' => 'ada@example.com',
            'department' => 'Operations',
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->upload($this->admin(), 'staff', ['email', 'department'], [
            ['ada@example.com', 'Finance'],
        ]);

        $this->assertSame('Finance', $staff->fresh()->department);
        $this->assertSame(1, session('import_result')['updated']);
    }

    public function test_skip_leaves_the_record_alone(): void
    {
        $staff = User::factory()->create([
            'email' => 'ada@example.com',
            'department' => 'Operations',
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->upload(
            $this->admin(),
            'staff',
            ['email', 'department'],
            [['ada@example.com', 'Finance']],
            extra: ['duplicates' => 'skip'],
        );

        $this->assertSame('Operations', $staff->fresh()->department);
        $this->assertSame(1, session('import_result')['skipped']);
    }

    public function test_reject_treats_a_collision_as_a_mistake(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->upload(
            $this->admin(),
            'staff',
            ['email', 'department'],
            [['ada@example.com', 'Finance']],
            extra: ['duplicates' => 'reject'],
        );

        $this->assertSame(1, session('import_result')['failed']);
    }

    /**
     * A correction file carries the two columns being corrected. Emptying
     * every other field on the record would make that unusable.
     */
    public function test_a_column_left_out_of_the_file_is_left_alone(): void
    {
        $staff = User::factory()->create([
            'email' => 'ada@example.com',
            'position' => 'Warehouse Supervisor',
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->upload($this->admin(), 'staff', ['email', 'department'], [
            ['ada@example.com', 'Finance'],
        ]);

        $this->assertSame('Warehouse Supervisor', $staff->fresh()->position);
    }

    // Reading a file the way a spreadsheet wrote it.

    public function test_headers_are_matched_loosely(): void
    {
        Location::factory()->create(['name' => 'TGM Ikeja']);

        $this->upload(
            $this->admin(),
            'staff',
            [' EMAIL ', 'Name', 'Location', 'Employee ID'],
            [['new@example.com', 'New Person', 'TGM Ikeja', 'TGM-0777']],
        );

        $this->assertDatabaseHas('users', [
            'email' => 'new@example.com',
            'employee_id' => 'TGM-0777',
        ]);
    }

    public function test_dates_are_read_in_the_shapes_a_spreadsheet_writes_them(): void
    {
        Location::factory()->create(['name' => 'TGM Ikeja']);

        $this->upload($this->admin(), 'staff', ['email', 'name', 'location', 'hired_at'], [
            ['a@example.com', 'A', 'TGM Ikeja', '2024-03-01'],
            ['b@example.com', 'B', 'TGM Ikeja', '01/03/2024'],
            ['c@example.com', 'C', 'TGM Ikeja', '1 March 2024'],
        ]);

        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
            $this->assertSame(
                '2024-03-01',
                User::query()->where('email', $email)->sole()->hired_at->toDateString(),
                "{$email} did not read its hire date",
            );
        }
    }

    public function test_blank_lines_are_skipped(): void
    {
        Location::factory()->create(['name' => 'TGM Ikeja']);

        $this->upload($this->admin(), 'staff', ['email', 'name', 'location'], [
            ['new@example.com', 'New Person', 'TGM Ikeja'],
            ['', '', ''],
        ]);

        $this->assertSame(1, session('import_result')['total']);
    }

    // Staff.

    public function test_an_imported_starter_gets_a_password_nobody_was_given(): void
    {
        Location::factory()->create(['name' => 'TGM Ikeja']);

        $this->upload($this->admin(), 'staff', ['email', 'name', 'location'], [
            ['new@example.com', 'New Person', 'TGM Ikeja'],
        ]);

        $user = User::query()->where('email', 'new@example.com')->sole();

        $this->assertNotEmpty($user->password);
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_somebody_who_works_a_shift_needs_a_site(): void
    {
        $this->upload($this->admin(), 'staff', ['email', 'name'], [
            ['new@example.com', 'New Person'],
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
        $this->assertStringContainsString(
            'site',
            session('import_result')['failures'][0]['messages'][0],
        );
    }

    public function test_an_administrator_may_be_imported_without_a_site(): void
    {
        $this->upload($this->admin(), 'staff', ['email', 'name', 'role'], [
            ['boss@example.com', 'The Boss', Role::SUPER_ADMIN],
        ]);

        $this->assertDatabaseHas('users', ['email' => 'boss@example.com', 'location_id' => null]);
    }

    public function test_a_new_starter_lands_on_probation_unless_the_file_says_otherwise(): void
    {
        Location::factory()->create(['name' => 'TGM Ikeja']);

        $this->upload($this->admin(), 'staff', ['email', 'name', 'location'], [
            ['new@example.com', 'New Person', 'TGM Ikeja'],
        ]);

        $this->assertSame(
            EmploymentStatus::Probation,
            User::query()->where('email', 'new@example.com')->sole()->employment_status,
        );
    }

    public function test_deactivating_somebody_stamps_the_date_and_reactivating_clears_it(): void
    {
        $staff = User::factory()->create([
            'email' => 'ada@example.com',
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->upload($this->admin(), 'staff', ['email', 'is_active'], [['ada@example.com', 'no']]);
        $this->assertNotNull($staff->fresh()->deactivated_at);

        $this->upload($this->admin(), 'staff', ['email', 'is_active'], [['ada@example.com', 'yes']]);
        $this->assertNull($staff->fresh()->deactivated_at);
    }

    /**
     * Writing somebody's record onto the wrong person is not a mistake a bulk
     * import gets to make quietly.
     */
    public function test_a_row_whose_staff_id_and_email_disagree_is_rejected(): void
    {
        User::factory()->create([
            'employee_id' => 'TGM-0001',
            'email' => 'ada@example.com',
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->upload($this->admin(), 'employee-profiles', ['employee_id', 'email', 'first_name'], [
            ['TGM-0001', 'someone.else@example.com', 'Ada'],
        ]);

        $this->assertSame(1, session('import_result')['failed']);
    }

    // Sites.

    public function test_a_site_is_created_with_its_own_working_day(): void
    {
        $this->upload($this->admin(), 'locations', [
            'name', 'address', 'work_starts_at', 'work_ends_at', 'workdays', 'timezone',
        ], [
            ['TGM Abuja', '5 Wuse II', '08:30', '16:30', '1;2;3;4;5;6', 'Africa/Lagos'],
        ])->assertSessionHasNoErrors();

        $site = Location::query()->where('name', 'TGM Abuja')->sole();

        $this->assertSame([1, 2, 3, 4, 5, 6], $site->workdays);
        $this->assertSame('08:30', substr($site->work_starts_at, 0, 5));
    }

    public function test_a_site_cannot_close_before_it_opens(): void
    {
        $this->upload($this->admin(), 'locations', ['name', 'address', 'work_starts_at', 'work_ends_at'], [
            ['TGM Abuja', '5 Wuse II', '17:00', '09:00'],
        ]);

        $this->assertSame(1, session('import_result')['failed']);
        $this->assertDatabaseMissing('locations', ['name' => 'TGM Abuja']);
    }

    // Roles.

    public function test_a_role_takes_the_permissions_the_file_names(): void
    {
        $this->upload($this->admin(), 'roles', ['name', 'permissions'], [
            ['Shift Supervisor', 'admin.dashboard;requests.approve'],
        ])->assertSessionHasNoErrors();

        $role = Role::query()->where('slug', 'shift_supervisor')->sole();

        $this->assertFalse($role->is_system);
        $this->assertTrue($role->hasPermission(Permission::ApproveRequests));
        $this->assertFalse($role->hasPermission(Permission::ManagePayroll));
    }

    /**
     * A file that could narrow the super admin role is a file that could lock
     * the last administrator out of the system.
     */
    public function test_the_super_admin_role_is_skipped(): void
    {
        $this->upload($this->admin(), 'roles', ['slug', 'name', 'permissions'], [
            [Role::SUPER_ADMIN, 'Super Admin', 'admin.dashboard'],
        ]);

        $this->assertSame(1, session('import_result')['skipped']);
        $this->assertTrue(
            Role::query()->where('slug', Role::SUPER_ADMIN)->sole()->hasPermission(Permission::ManagePayroll),
        );
    }

    public function test_a_permission_the_system_does_not_have_is_rejected(): void
    {
        $this->upload($this->admin(), 'roles', ['name', 'permissions'], [
            ['Shift Supervisor', 'admin.dashboard;make.tea'],
        ]);

        $this->assertSame(1, session('import_result')['failed']);
        $this->assertDatabaseMissing('roles', ['slug' => 'shift_supervisor']);
    }

    // Leave types.

    public function test_a_leave_type_is_created_with_its_policy_rules(): void
    {
        $this->upload($this->admin(), 'leave-types', [
            'name', 'days_per_year', 'min_service_months', 'requires_evidence', 'is_paid',
        ], [
            ['Study break', '5', '12', 'yes', 'no'],
        ])->assertSessionHasNoErrors();

        $type = LeaveType::query()->where('slug', 'study-break')->sole();

        $this->assertSame(5, $type->days_per_year);
        $this->assertSame(12, $type->min_service_months);
        $this->assertTrue($type->requires_evidence);
        $this->assertFalse($type->is_paid);
    }

    public function test_an_anchor_without_a_window_is_rejected(): void
    {
        $this->upload($this->admin(), 'leave-types', ['name', 'anchor'], [
            ['Anniversary leave', 'hire_date'],
        ]);

        $this->assertSame(1, session('import_result')['failed']);
    }

    // Employee records.

    public function test_a_profile_is_filled_in_and_the_display_name_recomposed(): void
    {
        $staff = User::factory()->withoutProfile()->create([
            'email' => 'ada@example.com',
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->upload($this->admin(), 'employee-profiles', [
            'email', 'first_name', 'last_name', 'gender', 'date_of_birth',
            'country_of_origin', 'state_of_origin', 'phone',
        ], [
            ['ada@example.com', 'Ada', 'Eze', 'Female', '1994-06-12', 'ng', 'Enugu State', '08012345678'],
        ])->assertSessionHasNoErrors();

        $staff->refresh();

        $this->assertSame('Ada Eze', $staff->name);
        $this->assertSame('08012345678', $staff->phone);
        $this->assertSame('NG', $staff->profile->country_of_origin);
        $this->assertNotNull($staff->profile->completed_at);
    }

    public function test_an_address_lands_against_the_right_person(): void
    {
        $staff = User::factory()->create([
            'employee_id' => 'TGM-0001',
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->upload($this->admin(), 'employee-addresses', [
            'employee_id', 'label', 'street', 'city', 'country',
        ], [
            ['TGM-0001', 'Residential', '14 Ogunlana Drive', 'Surulere', 'NG'],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employee_addresses', [
            'user_id' => $staff->id,
            'label' => 'Residential',
            'street' => '14 Ogunlana Drive',
        ]);
    }

    public function test_a_next_of_kin_needs_a_number_somebody_can_ring(): void
    {
        User::factory()->create([
            'employee_id' => 'TGM-0001',
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->upload($this->admin(), 'employee-relations', [
            'employee_id', 'kind', 'relation_name', 'relationship',
        ], [
            ['TGM-0001', RelationKind::NextOfKin->value, 'Emeka Eze', 'Spouse'],
        ]);

        $this->assertSame(1, session('import_result')['failed']);

        $this->upload($this->admin(), 'employee-relations', [
            'employee_id', 'kind', 'relation_name', 'relationship', 'relation_phone',
        ], [
            ['TGM-0001', RelationKind::Dependant->value, 'Chidi Eze', 'Son', ''],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employee_relations', ['name' => 'Chidi Eze']);
    }

    // Salaries.

    public function test_a_salary_is_set_from_the_annual_package(): void
    {
        $staff = User::factory()->create([
            'email' => 'ada@example.com',
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->upload($this->admin(), 'salaries', ['email', 'annual_gross', 'pension_applies'], [
            ['ada@example.com', '4,800,000', 'no'],
        ])->assertSessionHasNoErrors();

        $salary = SalaryProfile::query()->where('user_id', $staff->id)->sole();

        $this->assertSame(4800000.0, $salary->annual_gross);
        $this->assertFalse($salary->pension_applies);
    }

    public function test_an_administrator_draws_no_salary(): void
    {
        $boss = User::factory()->superAdmin()->create(['email' => 'boss@example.com']);

        $this->upload($this->admin(), 'salaries', ['email', 'annual_gross'], [
            ['boss@example.com', '4800000'],
        ]);

        $this->assertSame(1, session('import_result')['failed']);
        $this->assertDatabaseMissing('salary_profiles', ['user_id' => $boss->id]);
    }

    // Attendance.

    public function test_lateness_is_worked_out_against_the_site_rather_than_taken_from_the_file(): void
    {
        $site = Location::factory()->create([
            'name' => 'TGM Ikeja',
            'work_starts_at' => '09:00:00',
            'grace_minutes' => 10,
            'timezone' => 'Africa/Lagos',
        ]);

        $staff = User::factory()->create([
            'employee_id' => 'TGM-0001',
            'location_id' => $site->id,
        ]);

        $this->upload($this->admin(), 'attendance', [
            'employee_id', 'work_date', 'clocked_in_at', 'clocked_out_at', 'break_minutes',
        ], [
            ['TGM-0001', '2026-03-02', '09:47', '17:12', '45'],
        ])->assertSessionHasNoErrors();

        $day = Attendance::query()->where('user_id', $staff->id)->sole();

        $this->assertSame(AttendanceStatus::Late, $day->status);
        $this->assertSame(47, $day->late_minutes);
        // 09:47 to 17:12 is 445 minutes, less a 45 minute break.
        $this->assertSame(400, $day->worked_minutes);
    }

    public function test_an_arrival_inside_the_grace_window_is_not_lateness(): void
    {
        $site = Location::factory()->create([
            'name' => 'TGM Ikeja',
            'work_starts_at' => '09:00:00',
            'grace_minutes' => 10,
        ]);

        User::factory()->create(['employee_id' => 'TGM-0001', 'location_id' => $site->id]);

        $this->upload($this->admin(), 'attendance', ['employee_id', 'work_date', 'clocked_in_at'], [
            ['TGM-0001', '2026-03-02', '09:07'],
        ]);

        $day = Attendance::query()->sole();

        $this->assertSame(AttendanceStatus::Grace, $day->status);
        $this->assertSame(0, $day->late_minutes);
    }

    public function test_attendance_cannot_be_recorded_for_a_day_still_to_come(): void
    {
        User::factory()->create([
            'employee_id' => 'TGM-0001',
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->upload($this->admin(), 'attendance', ['employee_id', 'work_date'], [
            ['TGM-0001', now()->addWeek()->toDateString()],
        ]);

        $this->assertSame(1, session('import_result')['failed']);
    }

    // Leave history.

    public function test_leave_history_lands_decided_and_counted_in_working_days(): void
    {
        $site = Location::factory()->create(['workdays' => [1, 2, 3, 4, 5]]);
        $staff = User::factory()->create([
            'employee_id' => 'TGM-0001',
            'location_id' => $site->id,
        ]);

        $this->upload($this->admin(), 'leave-records', [
            'employee_id', 'leave_type', 'start_date', 'end_date',
        ], [
            // A Monday to the Friday of the week after: ten working days
            // across a fortnight that holds fourteen.
            ['TGM-0001', 'annual', '2026-04-06', '2026-04-17'],
        ])->assertSessionHasNoErrors();

        $leave = LeaveRequest::query()->where('user_id', $staff->id)->sole();

        $this->assertSame(10, $leave->days);
        $this->assertSame(RequestStatus::Approved, $leave->status);
        $this->assertNotNull($leave->decided_at);
        // Nobody in this system raised it or signed it off.
        $this->assertNull($leave->supervisor_id);
        $this->assertSame(0, $leave->approvals_required);
    }

    public function test_leave_history_does_not_appear_in_anybody_s_approvals_inbox(): void
    {
        $site = Location::factory()->create();
        User::factory()->create(['employee_id' => 'TGM-0001', 'location_id' => $site->id]);

        $this->upload($this->admin(), 'leave-records', [
            'employee_id', 'leave_type', 'start_date', 'end_date',
        ], [
            ['TGM-0001', 'annual', '2026-04-06', '2026-04-10'],
        ]);

        $approver = User::factory()->approver()->create(['location_id' => $site->id]);

        $this->actingAs($approver)
            ->get('/approvals')
            ->assertInertia(fn ($page) => $page->where('leave', []));
    }

    public function test_leave_of_an_unknown_type_is_rejected(): void
    {
        User::factory()->create([
            'employee_id' => 'TGM-0001',
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->upload($this->admin(), 'leave-records', [
            'employee_id', 'leave_type', 'start_date', 'end_date',
        ], [
            ['TGM-0001', 'sabbatical', '2026-04-06', '2026-04-10'],
        ]);

        $this->assertSame(1, session('import_result')['failed']);
    }

    // Restricted periods.

    public function test_a_restricted_period_takes_its_exempt_leave_types(): void
    {
        $this->upload($this->admin(), 'restricted-periods', [
            'name', 'start_date', 'end_date', 'exempt_leave_types', 'exempt_marital_statuses',
        ], [
            ['Year-end count', '2026-12-15', '2026-12-31', 'compassionate;maternity', 'Married'],
        ])->assertSessionHasNoErrors();

        $period = LeaveRestrictedPeriod::query()->sole();

        $this->assertSame(['Married'], $period->exempt_marital_statuses);
        $this->assertEqualsCanonicalizing(
            ['compassionate', 'maternity'],
            $period->leaveTypes->pluck('slug')->all(),
        );
    }

    // The reference files, which are the contract with whoever fills a file in.

    public function test_the_reference_names_every_column_the_importer_reads(): void
    {
        $csv = $this->actingAs($this->admin())
            ->get('/admin/imports/salaries/reference')
            ->streamedContent();

        foreach (app(ImportRegistry::class)->find('salaries')->columns() as $column) {
            $this->assertStringContainsString($column->name, $csv);
        }
    }
}
