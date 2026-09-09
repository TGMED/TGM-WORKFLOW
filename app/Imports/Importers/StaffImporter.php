<?php

namespace App\Imports\Importers;

use App\Enums\EmploymentStatus;
use App\Enums\ImportDuplicates;
use App\Enums\Permission;
use App\Imports\BaseImporter;
use App\Imports\ImportColumn;
use App\Imports\ImportResult;
use App\Imports\ImportRow;
use App\Models\Department;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The people. Everything after this in the running order hangs off a row
 * loaded here.
 *
 * Passwords are the awkward part of a bulk load: a file of them is a file
 * nobody should be emailing around, and a blank column cannot become a blank
 * password. A row without one gets a long random password it is not told, so
 * the account exists and is reached through the forgotten-password flow.
 */
class StaffImporter extends BaseImporter
{
    public function key(): string
    {
        return 'staff';
    }

    public function label(): string
    {
        return 'Staff';
    }

    public function description(): string
    {
        return 'The people on the books: who they are, what they do, which site they clock in at and which role they hold.';
    }

    public function permission(): Permission
    {
        return Permission::ManageStaff;
    }

    /**
     * @return array<int, string>
     */
    public function dependsOn(): array
    {
        return ['locations', 'roles'];
    }

    public function matchedOn(): string
    {
        return 'the email address, case-insensitively';
    }

    /**
     * @return array<int, string>
     */
    public function notes(): array
    {
        return [
            'Leave the password column out of the file. Anyone imported without one is given a long random password nobody is told, and reaches their account through the forgotten-password link on the sign-in page.',
            'Everyone who works a shift belongs to a site. Only super admins may be imported without one, since they run the system rather than punch a clock.',
            'Staff arrive on probation unless the file says otherwise. Set employment_status to confirmed for anyone already past it, and give the date they were confirmed.',
            'Nobody is deactivated by leaving them out of the file. Set is_active to no on the row to deactivate somebody; the import never touches a record no row names.',
            'This sheet does not carry the HR record — date of birth, bank details, next of kin. Those come in on the employee profiles, addresses and relations sheets.',
        ];
    }

    /**
     * @return array<int, ImportColumn>
     */
    public function columns(): array
    {
        return [
            ImportColumn::required('email', 'Work email. This is what a row is matched on and what the person signs in with.', 'ada.eze@example.com', format: 'Email address'),
            ImportColumn::required('name', 'Full name, as it should appear on screen.', 'Ada Eze'),
            ImportColumn::make('employee_id', 'The staff ID the company issues. Must be unique, and is what the other sheets point at.', 'TGM-0148'),
            ImportColumn::make('phone', 'Primary phone number.', '+2348012345678'),
            ImportColumn::make('department', 'The department this person sits in, by name. One that does not exist yet is created.', 'Operations'),
            ImportColumn::make('position', 'Their job title.', 'Warehouse Supervisor'),
            ImportColumn::make(
                'roles',
                'The roles they hold, by slug, separated by a pipe. Left blank a new person is given staff. Heads of department and team leads are named on the departments page instead, so those two slugs are refused here.',
                Role::STAFF.'|'.Role::APPROVER,
                format: 'Role slugs, pipe-separated',
            ),
            ImportColumn::make(
                'location',
                'The site they clock in at, by name or by ID. Required for everyone but super admins.',
                'TGM Ikeja',
                format: 'Site name or ID',
            ),
            ImportColumn::make('hired_at', 'The day they started. Service-based leave rules are counted from this.', '2024-03-01', format: 'Date'),
            ImportColumn::make(
                'employment_status',
                'Whether probation has been passed. New staff arrive on probation unless this says otherwise.',
                EmploymentStatus::Probation->value,
                format: 'probation or confirmed',
                accepts: array_column(EmploymentStatus::cases(), 'value'),
            ),
            ImportColumn::make('confirmed_at', 'The day probation was passed, which a confirmation letter quotes.', '2024-09-01', format: 'Date'),
            ImportColumn::boolean('is_active', 'Whether they may sign in.'),
        ];
    }

    public function import(ImportRow $row, ImportDuplicates $duplicates, ImportResult $result): void
    {
        $email = $row->string('email');

        $existing = $email === null
            ? null
            : User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

        if ($existing !== null && $duplicates === ImportDuplicates::Skip) {
            $result->skipped();

            return;
        }

        if ($existing !== null && $duplicates === ImportDuplicates::Reject) {
            $this->reject("{$email} is already on the staff list.");
        }

        $roles = $this->roles($row->string('roles') ?? $row->string('role'), $existing);
        // The site rule reads whether this person clocks in, which is a
        // question about the whole set of roles, not any one of them.
        $role = $roles->firstWhere('slug', Role::SUPER_ADMIN) ?? $roles->first();

        $data = $this->validate(
            [
                'email' => $email,
                'name' => $row->string('name'),
                'employee_id' => $row->string('employee_id'),
                'phone' => $row->string('phone'),
                'position' => $row->string('position'),
                'hired_at' => $row->date('hired_at')?->toDateString(),
                'employment_status' => $row->string('employment_status'),
                'confirmed_at' => $row->date('confirmed_at')?->toDateString(),
                'is_active' => $row->boolean('is_active'),
            ],
            [
                'email' => ['required', 'string', 'email', 'max:190', Rule::unique('users', 'email')->ignore($existing?->id)],
                'name' => [$existing === null ? 'required' : 'nullable', 'string', 'max:120'],
                'employee_id' => ['nullable', 'string', 'max:40', Rule::unique('users', 'employee_id')->ignore($existing?->id)],
                'phone' => ['nullable', 'string', 'max:30'],
                'position' => ['nullable', 'string', 'max:80'],
                'hired_at' => ['nullable', 'date', 'before_or_equal:today'],
                'employment_status' => ['nullable', Rule::enum(EmploymentStatus::class)],
                'confirmed_at' => ['nullable', 'date'],
                'is_active' => ['nullable', 'boolean'],
            ],
            [
                'email.unique' => 'Another member of staff already uses that email.',
                'employee_id.unique' => 'That staff ID already belongs to someone else.',
                'hired_at.before_or_equal' => 'A start date in the future is not something the leave rules can count service from.',
            ],
        );

        $data['location_id'] = $this->locationId($row, $role, $existing);
        $data['department_id'] = $this->departmentId($row, $existing);

        // Deactivating somebody has a second half: the app reads the date to
        // say when they were let go, and a reactivation has to clear it.
        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            $data['deactivated_at'] = $data['is_active'] ? null : Carbon::now();
        }

        if ($existing !== null) {
            $existing->update($this->presentKeepingNulls($data));
            $existing->roles()->sync($roles->pluck('id')->all());
            $result->updated();

            return;
        }

        $user = User::query()->create([
            ...$this->presentKeepingNulls($data),
            // Never a value from the file. A password column would put every
            // new starter's credentials in a spreadsheet somebody emails.
            'password' => Str::password(32),
        ]);

        $user->roles()->sync($roles->pluck('id')->all());

        $result->created();
    }

    /**
     * The roles a row names, or the ones the person already holds. Several may
     * be listed, separated by a pipe. A new starter with nothing in the column
     * is staff, which is what an import of a headcount file almost always
     * means.
     *
     * @return Collection<int, Role>
     */
    private function roles(?string $column, ?User $existing): Collection
    {
        $slugs = collect(explode('|', (string) $column))
            ->map(fn (string $slug): string => trim($slug))
            ->filter()
            ->unique()
            ->values();

        if ($slugs->isEmpty()) {
            $slugs = $existing === null
                ? collect([Role::STAFF])
                : $existing->roles->pluck('slug');
        }

        if ($slugs->isEmpty()) {
            $slugs = collect([Role::STAFF]);
        }

        // Heads and leads come with people attached, which a spreadsheet has
        // no way to name. They are granted on the departments page instead.
        $reserved = $slugs->intersect(Role::assignedThroughDepartments());

        if ($reserved->isNotEmpty()) {
            $this->reject($reserved->implode(', ').' is granted on the departments page, where the people it covers are named at the same time, so it cannot be set from a file.');
        }

        $roles = Role::query()->whereIn('slug', $slugs)->get();

        $missing = $slugs->diff($roles->pluck('slug'));

        if ($missing->isNotEmpty()) {
            $this->reject('No role has the slug '.$missing->implode(', ').'. Import the roles sheet first, or use one of: '.implode(', ', Role::query()->orderBy('slug')->pluck('slug')->all()).'.');
        }

        return $roles;
    }

    /**
     * The department a row names, matched on the name case-insensitively so a
     * file that says "finance" lands in the same department as one that says
     * "Finance". A department the company does not have yet is created, since
     * an import of a headcount file is the usual way a new one arrives.
     *
     * A blank column leaves an existing person where they are, rather than
     * taking their department away: a sheet carrying only an email and a phone
     * number is a correction, not a reorganisation.
     */
    private function departmentId(ImportRow $row, ?User $existing): ?int
    {
        $name = trim((string) $row->string('department'));

        if ($name === '') {
            return $existing?->department_id;
        }

        $department = Department::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($department !== null) {
            return $department->id;
        }

        return Department::query()->create([
            'name' => $name,
            'slug' => Department::uniqueSlug($name),
            'is_active' => true,
        ])->id;
    }

    /**
     * The site, by name or by ID. Everyone who works a shift needs one; a
     * super admin runs the system rather than clocks in, so theirs is
     * optional.
     */
    private function locationId(ImportRow $row, Role $role, ?User $existing): ?int
    {
        $given = $row->string('location');

        if ($given === null) {
            $current = $existing?->location_id;

            if ($current === null && $role->slug !== Role::SUPER_ADMIN) {
                $this->reject('Give the site this person clocks in at. Only super admins may be imported without one.');
            }

            return $current;
        }

        $location = Location::query()
            ->when(
                ctype_digit($given),
                fn ($query) => $query->where('id', (int) $given),
                fn ($query) => $query->whereRaw('LOWER(name) = ?', [mb_strtolower($given)]),
            )
            ->first();

        if ($location === null) {
            $this->reject("No site is called {$given}. Import the sites sheet first.");
        }

        return $location->id;
    }

    /**
     * Like present(), but keeps a deliberate null: deactivated_at is set to
     * null on purpose when somebody is reactivated, and dropping it would
     * leave the old date on the record.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function presentKeepingNulls(array $data): array
    {
        $keep = ['deactivated_at', 'location_id'];

        return array_filter(
            $data,
            fn (mixed $value, string $key): bool => $value !== null || in_array($key, $keep, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
