<?php

namespace Tests\Feature;

use App\Enums\PayrollRunStatus;
use App\Enums\Permission;
use App\Models\Location;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\PayrollRunBuilder;
use App\Services\PensionSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What is owed to the pension administrator for a month, and whether it has
 * been paid over.
 */
class PensionScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        $role = Role::query()->create([
            'slug' => 'finance_'.Role::query()->count(),
            'name' => 'Finance officer',
            'is_system' => false,
        ]);

        $role->syncPermissions([Permission::ManagePayroll]);

        return User::factory()->roles($role->slug)->create([
            'location_id' => Location::factory()->create()->id,
        ]);
    }

    private function staff(?string $rsa = 'PEN100200300'): User
    {
        $user = User::factory()->create([
            'location_id' => Location::factory()->create()->id,
        ]);

        SalaryProfile::query()->create([
            'user_id' => $user->id,
            'annual_gross' => 6_000_000,
            'pension_applies' => true,
            'nhf_applies' => true,
        ]);

        // Through the relation, which fills in whose profile it is. The
        // factory may already have made one, so this updates rather than
        // insisting on a second.
        $user->profile()->updateOrCreate([], [
            'rsa_number' => $rsa,
            'pfa_name' => $rsa === null ? null : 'Stanbic IBTC Pension',
        ]);

        return $user;
    }

    private function monthRun(bool $finalised = true): PayrollRun
    {
        $run = PayrollRun::query()->create([
            'year' => Carbon::now()->year,
            'month' => Carbon::now()->month,
            'status' => PayrollRunStatus::Draft,
        ]);

        app(PayrollRunBuilder::class)->build($run);

        if ($finalised) {
            $run->update([
                'status' => PayrollRunStatus::Finalised,
                'finalised_at' => Carbon::now(),
            ]);
        }

        return $run->refresh();
    }

    public function test_both_contributions_are_frozen_onto_the_payslip(): void
    {
        $staff = $this->staff();
        $run = $this->monthRun();

        $payslip = $run->payslips()->where('user_id', $staff->id)->firstOrFail();

        // 40/20/10 of 6,000,000 is 4,200,000 pensionable, so 8% and 10% of it
        // over twelve months.
        $this->assertSame(28000.0, $payslip->employee_pension);
        $this->assertSame(35000.0, $payslip->employer_pension);
    }

    public function test_the_schedule_lists_everybody_with_their_account_details(): void
    {
        $staff = $this->staff();
        $run = $this->monthRun();

        $lines = app(PensionSchedule::class)->forRun($run);
        $line = $lines->firstWhere('user_id', $staff->id);

        $this->assertSame('PEN100200300', $line['rsa_number']);
        $this->assertSame('Stanbic IBTC Pension', $line['pfa_name']);
        $this->assertSame(63000.0, $line['total']);
        $this->assertTrue($line['ready']);
    }

    public function test_somebody_with_no_rsa_number_is_flagged_rather_than_dropped(): void
    {
        $this->staff(rsa: null);
        $run = $this->monthRun();

        $schedule = app(PensionSchedule::class);
        $lines = $schedule->forRun($run);

        $this->assertCount(1, $lines);
        $this->assertFalse($lines->first()['ready']);
        $this->assertSame(1, $schedule->totals($lines)['missing_rsa']);
    }

    public function test_the_page_totals_what_has_to_be_paid_over(): void
    {
        $this->staff();
        $this->staff();
        $run = $this->monthRun();

        $this->actingAs($this->officer())
            ->get("/admin/payroll/{$run->id}/pension")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/PensionSchedule')
                ->where('totals.people', 2)
                ->where('totals.employee', 56000)
                ->where('totals.employer', 70000)
                ->where('totals.total', 126000));
    }

    public function test_a_draft_schedule_cannot_be_downloaded(): void
    {
        $this->staff();
        $run = $this->monthRun(finalised: false);

        $this->actingAs($this->officer())
            ->get("/admin/payroll/{$run->id}/pension/export")
            ->assertNotFound();
    }

    public function test_a_finalised_schedule_downloads_as_a_spreadsheet(): void
    {
        $this->staff();
        $run = $this->monthRun();

        $response = $this->actingAs($this->officer())
            ->get("/admin/payroll/{$run->id}/pension/export")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('RSA number', $csv);
        $this->assertStringContainsString('PEN100200300', $csv);
    }

    public function test_a_remittance_is_recorded_against_the_run(): void
    {
        $this->staff();
        $run = $this->monthRun();
        $officer = $this->officer();

        $this->actingAs($officer)
            ->post("/admin/payroll/{$run->id}/pension/remit", [
                'pension_reference' => 'FBN/2026/0912',
            ])
            ->assertSessionHasNoErrors();

        $run->refresh();

        $this->assertSame('FBN/2026/0912', $run->pension_reference);
        $this->assertNotNull($run->pension_remitted_at);
        $this->assertSame($officer->id, $run->pension_remitted_by_id);
    }

    public function test_a_draft_run_cannot_be_remitted(): void
    {
        $this->staff();
        $run = $this->monthRun(finalised: false);

        $this->actingAs($this->officer())
            ->post("/admin/payroll/{$run->id}/pension/remit", [
                'pension_reference' => 'FBN/2026/0912',
            ]);

        $this->assertNull($run->refresh()->pension_remitted_at);
    }

    public function test_staff_cannot_read_the_schedule(): void
    {
        $staff = $this->staff();
        $run = $this->monthRun();

        $this->actingAs($staff)
            ->get("/admin/payroll/{$run->id}/pension")
            ->assertForbidden();
    }
}
