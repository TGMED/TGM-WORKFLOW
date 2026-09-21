<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PayrollSettingsRequest;
use App\Models\PayrollSettings;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Rates for one person rather than for the company.
 *
 * A personal set replaces the company's outright: every figure on that
 * person's payslip then comes from their own row. It does not follow the
 * company rates when those move, which is the trade for being able to point at
 * one row and say that is what somebody was paid under.
 *
 * Behind `payroll.manage` on the route, as the rest of payroll is.
 */
class UserPayrollSettingsController extends Controller
{
    /**
     * Put somebody on their own rates, starting from a copy of the company's
     * so the form opens on real numbers rather than on defaults nobody chose.
     */
    public function store(User $user): RedirectResponse
    {
        // Administrators run the system and are not on the payroll, so there
        // are no rates for them to be put on.
        abort_unless($user->clocksIn(), 422, 'That person is not on the payroll.');

        PayrollSettings::copyToUser($user);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$user->name} is now on their own rates, copied from the company's. They will not follow company rate changes from here.",
        ]);
    }

    public function update(PayrollSettingsRequest $request, User $user): RedirectResponse
    {
        $settings = PayrollSettings::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $settings->update($request->payload());

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Rates saved for {$user->name}. Rebuild any draft run to apply them.",
        ]);
    }

    /**
     * Put somebody back on the company rates. The personal row goes; payslips
     * already run keep the figures they were run with.
     */
    public function destroy(User $user): RedirectResponse
    {
        PayrollSettings::query()->where('user_id', $user->id)->delete();

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$user->name} is back on the company rates. Payslips already run are unaffected.",
        ]);
    }
}
