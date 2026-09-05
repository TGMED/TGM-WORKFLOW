<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PayrollSettingsRequest;
use App\Models\PayrollSettings;
use Illuminate\Http\RedirectResponse;

class PayrollSettingsController extends Controller
{
    /**
     * Change the rules pay is worked out under. Runs already finalised keep
     * the figures they were finalised with — a payslip says what was paid,
     * not what today's rates would have paid.
     */
    public function update(PayrollSettingsRequest $request): RedirectResponse
    {
        PayrollSettings::current()->update($request->payload());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Payroll rules saved. Rebuild any draft run to apply them.',
        ]);
    }
}
