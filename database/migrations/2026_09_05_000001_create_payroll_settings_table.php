<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rules payroll runs on: how a gross package splits, what the statutory
 * deductions take, and the tax bands.
 *
 * All of it is data rather than code, for the same reason the leave policy is:
 * a rate is a number the finance team owns, and when the law moves they must
 * be able to follow it from a settings page rather than wait for a deploy.
 * One row, read by the calculator on every run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();

            $table->string('currency', 3)->default('NGN');

            // How an annual gross package is broken up. Basic, housing and
            // transport together are what pension is assessed on, so the split
            // is not cosmetic.
            $table->decimal('basic_percent', 5, 2)->default(40);
            $table->decimal('housing_percent', 5, 2)->default(20);
            $table->decimal('transport_percent', 5, 2)->default(10);

            // Statutory deductions.
            $table->decimal('pension_employee_percent', 5, 2)->default(8);
            $table->decimal('pension_employer_percent', 5, 2)->default(10);
            $table->decimal('nhf_percent', 5, 2)->default(2.5);

            // Relief on rent actually paid, taken off before tax is assessed.
            // The employee's annual rent is already on their HR record.
            $table->decimal('rent_relief_percent', 5, 2)->default(20);
            $table->decimal('rent_relief_cap', 14, 2)->default(500000);

            // Annual PAYE bands, cheapest first, as
            // [{"up_to": 800000, "rate": 0}, ..., {"up_to": null, "rate": 25}].
            // A null `up_to` is the top band and must come last.
            $table->json('tax_bands');

            $table->timestamps();
        });

        // Seeded to the Nigerian regime the company runs under as at the date
        // of this migration. These are defaults, not law as the app knows it:
        // finance owns the numbers and is expected to check them each year.
        DB::table('payroll_settings')->insert([
            'currency' => 'NGN',
            'basic_percent' => 40,
            'housing_percent' => 20,
            'transport_percent' => 10,
            'pension_employee_percent' => 8,
            'pension_employer_percent' => 10,
            'nhf_percent' => 2.5,
            'rent_relief_percent' => 20,
            'rent_relief_cap' => 500000,
            'tax_bands' => json_encode([
                ['up_to' => 800000, 'rate' => 0],
                ['up_to' => 3000000, 'rate' => 15],
                ['up_to' => 12000000, 'rate' => 18],
                ['up_to' => 25000000, 'rate' => 21],
                ['up_to' => 50000000, 'rate' => 23],
                ['up_to' => null, 'rate' => 25],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_settings');
    }
};
