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
        $request->user()->addresses()->create($request->validated());

        return $this->done('Address added.');
    }

    public function update(StoreEmployeeAddressRequest $request, EmployeeAddress $address): RedirectResponse
    {
        $this->authorise($request, $address);

        $address->update($request->validated());

        return $this->done('Address updated.');
    }

    public function destroy(Request $request, EmployeeAddress $address): RedirectResponse
    {
        $this->authorise($request, $address);

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
