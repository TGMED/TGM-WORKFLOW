<?php

namespace Tests\Feature;

use App\Enums\PayrollRunStatus;
use App\Enums\Permission;
use App\Models\Location;
use App\Models\PayrollRun;
use App\Models\PayrollSettings;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payroll works a package out annually and divides by twelve, because that is
 * how tax is assessed. Most of what is tested here is that the arithmetic
 * lands where a hand calculation does, and that a draft run stays invisible.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create([
            'location_id' => Location::factory()->create()->id,
        ]);
    }

    private function officer(): User
    {
        $role = Role::query()->create([
            'slug' => 'finance_'.Role::query()->count(),
            'name' => 'Finance officer',
            'is_system' => false,
        ]);

        $role->syncPermissions([Permission::ManagePayroll]);

        return User::factory()->create([
            'role_id' => $role->id,
            'location_id' => Location::factory()->create()->id,
        ]);
    }

    private function salaryFor(User $user, float $annual = 6000000): SalaryProfile
    {
        return SalaryProfile::query()->create([
            'user_id' => $user->id,
            'annual_gross' => $annual,
            'pension_applies' => true,
            'nhf_applies' => true,
        ]);
    }

    // The arithmetic.

    public function test_a_package_is_split_and_taxed_the_way_it_is_worked_out_by_hand(): void
    {
        $settings = PayrollSettings::current();
        $profile = $this->salaryFor($this->staff(), 6000000);

        $result = app(PayrollCalculator::class)->forProfile($profile, $settings, 0);

        // 40 / 20 / 10 of six million, and the rest as other allowances.
        $this->assertSame(200000.0, $result['earnings'][0]['amount']);
        $this->assertSame(100000.0, $result['earnings'][1]['amount']);
        $this->assertSame(50000.0, $result['earnings'][2]['amount']);
        $this->assertSame(150000.0, $result['earnings'][3]['amount']);

        // Pension at 8% of the 4.2m that is basic, housing and transport.
        $this->assertSame(336000.0, $result['annual']['employee_pension']);
        // NHF at 2.5% of the 2.4m basic.
        $this->assertSame(60000.0, $result['annual']['nhf']);

        $this->assertSame(5604000.0, $result['annual']['taxable']);
        // 2.2m at 15% plus 2,604,000 at 18%.
        $this->assertSame(798720.0, $result['annual']['tax']);

        $this->assertSame(500000.0, $result['gross_pay']);
        $this->assertSame(400440.0, $result['net_pay']);
    }

    public function test_income_inside_the_zero_band_is_not_taxed_at_all(): void
    {
        $settings = PayrollSettings::current();
        // Small enough that pension and NHF pull it under the 800k threshold.
        $profile = $this->salaryFor($this->staff(), 800000);

        $result = app(PayrollCalculator::class)->forProfile($profile, $settings, 0);

        $this->assertSame(0.0, $result['annual']['tax']);
        // A line worth nothing is left off the payslip rather than shown at 0.
        $this->assertNotContains('PAYE tax', array_column($result['deductions'], 'label'));
    }

    public function test_a_band_only_charges_the_slice_of_income_inside_it(): void
    {
        $settings = PayrollSettings::current();
        $calculator = app(PayrollCalculator::class);

        // A naira over a threshold is taxed a fraction more, not a whole band
        // more. This is the property that makes the table progressive.
        $under = $calculator->taxOn(3000000, $settings);
        $over = $calculator->taxOn(3000100, $settings);

        $this->assertSame(330000.0, $under);
        $this->assertSame(330018.0, $over);
    }

    public function test_rent_relief_is_capped(): void
    {
        $settings = PayrollSettings::current();
        $calculator = app(PayrollCalculator::class);

        // 20% of 2m is 400,000, which is under the cap.
        $this->assertSame(400000.0, $calculator->rentRelief(2000000, $settings));
        // 20% of 10m is 2m, which is not.
        $this->assertSame(500000.0, $calculator->rentRelief(10000000, $settings));
        $this->assertSame(0.0, $calculator->rentRelief(0, $settings));
    }

    public function test_opting_out_of_the_schemes_removes_the_deductions(): void
    {
        $settings = PayrollSettings::current();

        $profile = $this->salaryFor($this->staff(), 6000000);
        $profile->update(['pension_applies' => false, 'nhf_applies' => false]);

        $result = app(PayrollCalculator::class)->forProfile($profile->refresh(), $settings, 0);

        $labels = array_column($result['deductions'], 'label');

        $this->assertNotContains('Pension', $labels);
        $this->assertNotContains('National Housing Fund', $labels);
        $this->assertSame(0.0, $result['employer_pension']);
        // Nothing relieved, so the whole package is taxable.
        $this->assertSame(6000000.0, $result['annual']['taxable']);
    }

    public function test_a_payslip_adds_up_exactly_as_printed(): void
    {
        $settings = PayrollSettings::current();
        // An awkward figure, to catch rounding that only works on round ones.
        $profile = $this->salaryFor($this->staff(), 4373333.33);

        $result = app(PayrollCalculator::class)->forProfile($profile, $settings, 733333);

        $earnings = round(array_sum(array_column($result['earnings'], 'amount')), 2);
        $deductions = round(array_sum(array_column($result['deductions'], 'amount')), 2);

        $this->assertSame($earnings, $result['gross_pay']);
        $this->assertSame($deductions, $result['total_deductions']);
        $this->assertSame(round($earnings - $deductions, 2), $result['net_pay']);
    }

    // Running.

    public function test_a_run_builds_a_payslip_for_everyone_with_a_salary(): void
    {
        $paid = $this->staff();
        $this->salaryFor($paid);

        // No salary on file, so no payslip and no silent guess at one.
        $unpaid = $this->staff();

        $this->actingAs($this->officer())
            ->post('/admin/payroll', ['year' => 2026, 'month' => 8])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Payslip::query()->count());
        $this->assertTrue(Payslip::query()->where('user_id', $paid->id)->exists());
        $this->assertFalse(Payslip::query()->where('user_id', $unpaid->id)->exists());
    }

    public function test_a_month_cannot_be_run_twice(): void
    {
        $this->salaryFor($this->staff());

        $officer = $this->officer();

        $this->actingAs($officer)->post('/admin/payroll', ['year' => 2026, 'month' => 8]);
        $this->actingAs($officer)->post('/admin/payroll', ['year' => 2026, 'month' => 8]);

        $this->assertSame(1, PayrollRun::query()->count());
    }

    public function test_rebuilding_a_draft_picks_up_a_corrected_salary(): void
    {
        $staff = $this->staff();
        $profile = $this->salaryFor($staff, 6000000);

        $officer = $this->officer();
        $this->actingAs($officer)->post('/admin/payroll', ['year' => 2026, 'month' => 8]);

        $run = PayrollRun::query()->firstOrFail();
        $this->assertSame(500000.0, Payslip::query()->firstOrFail()->gross_pay);

        $profile->update(['annual_gross' => 12000000]);

        $this->actingAs($officer)
            ->post("/admin/payroll/{$run->id}/rebuild")
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Payslip::query()->count());
        $this->assertSame(1000000.0, Payslip::query()->firstOrFail()->gross_pay);
    }

    // Who sees what.

    public function test_a_draft_run_is_invisible_to_the_person_it_is_about(): void
    {
        $staff = $this->staff();
        $this->salaryFor($staff);

        $this->actingAs($this->officer())->post('/admin/payroll', ['year' => 2026, 'month' => 8]);

        $this->actingAs($staff)
            ->get('/payslips')
            ->assertInertia(fn ($page) => $page->has('payslips', 0));

        $payslip = Payslip::query()->firstOrFail();

        $this->actingAs($staff)->get("/payslips/{$payslip->id}")->assertNotFound();
    }

    public function test_finalising_publishes_the_payslips(): void
    {
        $staff = $this->staff();
        $this->salaryFor($staff);

        $officer = $this->officer();
        $this->actingAs($officer)->post('/admin/payroll', ['year' => 2026, 'month' => 8]);

        $run = PayrollRun::query()->firstOrFail();

        $this->actingAs($officer)
            ->post("/admin/payroll/{$run->id}/finalise")
            ->assertSessionHasNoErrors();

        $this->assertSame(PayrollRunStatus::Finalised, $run->refresh()->status);
        $this->assertSame($officer->id, $run->finalised_by_id);

        $this->actingAs($staff)
            ->get('/payslips')
            ->assertInertia(fn ($page) => $page->has('payslips', 1));

        $this->actingAs($staff)
            ->get('/payslips/'.Payslip::query()->firstOrFail()->id)
            ->assertInertia(fn ($page) => $page->component('Payslip'));
    }

    public function test_nobody_reads_a_colleagues_payslip(): void
    {
        $staff = $this->staff();
        $this->salaryFor($staff);

        $officer = $this->officer();
        $this->actingAs($officer)->post('/admin/payroll', ['year' => 2026, 'month' => 8]);
        $this->actingAs($officer)->post('/admin/payroll/'.PayrollRun::query()->firstOrFail()->id.'/finalise');

        $payslip = Payslip::query()->firstOrFail();

        // Not even the finance officer who ran it: that page is the run, not
        // somebody else's personal payslip route.
        $this->actingAs($this->staff())->get("/payslips/{$payslip->id}")->assertNotFound();
        $this->actingAs($officer)->get("/payslips/{$payslip->id}")->assertNotFound();
    }

    public function test_a_finalised_run_can_be_neither_rebuilt_nor_deleted(): void
    {
        $this->salaryFor($this->staff());

        $officer = $this->officer();
        $this->actingAs($officer)->post('/admin/payroll', ['year' => 2026, 'month' => 8]);

        $run = PayrollRun::query()->firstOrFail();
        $this->actingAs($officer)->post("/admin/payroll/{$run->id}/finalise");

        $this->actingAs($officer)->post("/admin/payroll/{$run->id}/rebuild")->assertForbidden();
        $this->actingAs($officer)->delete("/admin/payroll/{$run->id}")->assertForbidden();

        $this->assertSame(1, PayrollRun::query()->count());
    }

    public function test_payroll_is_shut_to_a_role_without_the_permission(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->get('/admin/payroll')->assertForbidden();
        $this->actingAs($staff)
            ->post('/admin/salaries', ['user_id' => $staff->id, 'annual_gross' => 9000000])
            ->assertForbidden();

        $this->assertSame(0, SalaryProfile::query()->count());
    }

    // Settings.

    public function test_a_split_that_overruns_the_package_is_refused(): void
    {
        $this->actingAs($this->officer())
            ->put('/admin/payroll-settings', [
                ...$this->settingsPayload(),
                'basic_percent' => 60,
                'housing_percent' => 30,
                'transport_percent' => 20,
            ])
            ->assertSessionHasErrors('basic_percent');
    }

    public function test_a_tax_table_with_no_open_band_is_refused(): void
    {
        $this->actingAs($this->officer())
            ->put('/admin/payroll-settings', [
                ...$this->settingsPayload(),
                'tax_bands' => [
                    ['up_to' => 800000, 'rate' => 0],
                    ['up_to' => 3000000, 'rate' => 15],
                ],
            ])
            ->assertSessionHasErrors('tax_bands');
    }

    public function test_changing_the_rates_leaves_a_finalised_run_alone(): void
    {
        $this->salaryFor($this->staff());

        $officer = $this->officer();
        $this->actingAs($officer)->post('/admin/payroll', ['year' => 2026, 'month' => 8]);
        $this->actingAs($officer)->post('/admin/payroll/'.PayrollRun::query()->firstOrFail()->id.'/finalise');

        $before = Payslip::query()->firstOrFail()->net_pay;

        $this->actingAs($officer)
            ->put('/admin/payroll-settings', [
                ...$this->settingsPayload(),
                'tax_bands' => [['up_to' => null, 'rate' => 50]],
            ])
            ->assertSessionHasNoErrors();

        // A payslip states what was paid, not what today's rates would pay.
        $this->assertSame($before, Payslip::query()->firstOrFail()->net_pay);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(): array
    {
        $settings = PayrollSettings::current();

        return [
            'currency' => $settings->currency,
            'basic_percent' => $settings->basic_percent,
            'housing_percent' => $settings->housing_percent,
            'transport_percent' => $settings->transport_percent,
            'pension_employee_percent' => $settings->pension_employee_percent,
            'pension_employer_percent' => $settings->pension_employer_percent,
            'nhf_percent' => $settings->nhf_percent,
            'rent_relief_percent' => $settings->rent_relief_percent,
            'rent_relief_cap' => $settings->rent_relief_cap,
            'tax_bands' => $settings->tax_bands,
        ];
    }
}
