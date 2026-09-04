<?php

use App\Enums\LeaveAnchor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The eligibility rules the HR policy attaches to each kind of leave. They
 * live on the row rather than in code so HR can change a rule from the
 * settings page without waiting for a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            // Managers and above draw a different allowance to everyone else.
            // Null means one allowance for the whole company, which is how
            // every type but annual leave works.
            $table->unsignedSmallInteger('days_per_year_manager')->nullable()->after('days_per_year');

            // Months of service before the type opens up, counted from
            // users.hired_at.
            $table->unsignedSmallInteger('min_service_months')->default(0)->after('days_per_year_manager');

            // Types closed to staff still on probation.
            $table->boolean('requires_confirmed')->default(false)->after('min_service_months');

            // Types the policy will not grant on somebody's word alone.
            $table->boolean('requires_evidence')->default(false)->after('requires_confirmed');

            // Entitlement that hangs off a date on the employee record and
            // lapses if it is not taken: birthday leave is six months from
            // the birthday and then gone.
            $table->string('anchor', 20)->nullable()->after('requires_evidence');
            $table->unsignedSmallInteger('window_months')->nullable()->after('anchor');
        });

        $this->seedPolicyTypes();
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn([
                'days_per_year_manager',
                'min_service_months',
                'requires_confirmed',
                'requires_evidence',
                'anchor',
                'window_months',
            ]);
        });
    }

    /**
     * The eleven types the policy defines. Annual and sick already exist, so
     * they take the new rule columns and keep the allowances the business is
     * running today — an allowance is a number HR owns, and a migration is no
     * place to change one behind their back.
     */
    protected function seedPolicyTypes(): void
    {
        $now = now();

        foreach ($this->policy() as $type) {
            $existing = DB::table('leave_types')->where('slug', $type['slug'])->first();

            if ($existing !== null) {
                DB::table('leave_types')
                    ->where('id', $existing->id)
                    ->update([
                        'min_service_months' => $type['min_service_months'],
                        'requires_confirmed' => $type['requires_confirmed'],
                        'requires_evidence' => $type['requires_evidence'],
                        'anchor' => $type['anchor'],
                        'window_months' => $type['window_months'],
                        'updated_at' => $now,
                    ]);

                continue;
            }

            DB::table('leave_types')->insert([
                ...$type,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Day counts are only set where the policy states one. Everywhere it is
     * silent the type ships uncapped, so nobody is turned away by a number
     * the policy never gave.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function policy(): array
    {
        return [
            $this->type('annual', 'Annual leave', 'Paid time off taken from the yearly allowance.', days: 10, managerDays: 15, minServiceMonths: 12),
            $this->type('maternity', 'Maternity leave', 'Leave around the birth of a child.', minServiceMonths: 12, requiresEvidence: true),
            $this->type('birthday', 'Birthday leave', 'A day off for your birthday, to be taken within six months of it.', days: 1, anchor: LeaveAnchor::Birthday->value, windowMonths: 6),
            $this->type('casual-illness', 'Casual illness', 'Short absence for a passing illness.', requiresEvidence: true),
            $this->type('personal', 'Personal leave', 'Time off for personal matters.'),
            $this->type('compassionate', 'Compassionate leave', 'Leave following a bereavement or family emergency.', requiresEvidence: true),
            $this->type('ongoing-medical', 'Ongoing medical leave', 'Leave for a continuing medical condition.', requiresConfirmed: true, requiresEvidence: true),
            $this->type('sick', 'Sick leave', 'Time off for illness or medical appointments.', requiresConfirmed: true, requiresEvidence: true),
            $this->type('exam', 'Exam leave', 'Unpaid time off to sit an examination.', isPaid: false),
            $this->type('study', 'Study leave', 'Unpaid time off to study.', isPaid: false),
            $this->type('leave-of-absence', 'Leave of absence', 'Unpaid leave agreed with the company.', isPaid: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function type(
        string $slug,
        string $name,
        string $description,
        ?int $days = null,
        ?int $managerDays = null,
        int $minServiceMonths = 0,
        bool $requiresConfirmed = false,
        bool $requiresEvidence = false,
        bool $isPaid = true,
        ?string $anchor = null,
        ?int $windowMonths = null,
    ): array {
        return [
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'days_per_year' => $days,
            'days_per_year_manager' => $managerDays,
            'min_service_months' => $minServiceMonths,
            'requires_confirmed' => $requiresConfirmed,
            'requires_evidence' => $requiresEvidence,
            'is_paid' => $isPaid,
            'anchor' => $anchor,
            'window_months' => $windowMonths,
        ];
    }
};
