<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBankDetailsRequest;
use Illuminate\Http\RedirectResponse;

class BankDetailsController extends Controller
{
    /**
     * Bank, pension and tax details all sit on the same profile row, so the
     * whole Bank Account tab saves in one go.
     */
    public function update(UpdateBankDetailsRequest $request): RedirectResponse
    {
        $request->user()
            ->profile()
            ->firstOrNew()
            ->fill($request->validated())
            ->save();

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Your bank details have been saved.',
        ]);
    }
}
