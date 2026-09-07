<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalaryProfileRequest;
use App\Models\SalaryProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Setting what somebody is paid. One salary per person, replaced rather than
 * versioned: the history that matters is the audit trail on the row and the
 * payslips already run, both of which outlive an edit here.
 */
class SalaryController extends Controller
{
    public function store(SalaryProfileRequest $request): RedirectResponse
    {
        $user = User::query()->findOrFail($request->integer('user_id'));

        // Administrators run the system and are not on the payroll, so there
        // is no salary for them to be given.
        abort_unless($user->clocksIn(), 422, 'That person is not on the payroll.');

        SalaryProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            $request->payload(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Salary saved for {$user->name}. Rebuild the draft run to pick it up.",
        ]);
    }

    public function destroy(SalaryProfile $salaryProfile): RedirectResponse
    {
        $name = $salaryProfile->user->name;

        $salaryProfile->delete();

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$name} taken off the payroll. Payslips already run are unaffected.",
        ]);
    }
}
