<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeAddressRequest;
use App\Models\EmployeeAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function store(StoreEmployeeAddressRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->addresses()->create($request->validated());

        // An address is one of the details the app holds people to, so adding
        // the first one can be what finishes the record.
        $user->stampProfileCompletion();

        return $this->done('Address added.');
    }

    public function update(StoreEmployeeAddressRequest $request, EmployeeAddress $address): RedirectResponse
    {
        $this->authorise($request, $address);

        $address->update($request->validated());

        return $this->done('Address updated.');
    }

    /**
     * Everyone has to keep an address on file, so the last one cannot be
     * removed. Getting it wrong is what the edit form is for.
     */
    public function destroy(Request $request, EmployeeAddress $address): RedirectResponse
    {
        $this->authorise($request, $address);

        if ($request->user()->addresses()->count() <= 1) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'We need an address on file. Edit this one rather than removing it.',
            ]);
        }

        $address->delete();

        return $this->done('Address removed.');
    }

    /**
     * Route model binding will hand over anyone's address, so check whose.
     */
    protected function authorise(Request $request, EmployeeAddress $address): void
    {
        abort_unless($address->user_id === $request->user()->id, 403);
    }

    protected function done(string $message): RedirectResponse
    {
        return back()->with('toast', ['type' => 'success', 'message' => $message]);
    }
}
