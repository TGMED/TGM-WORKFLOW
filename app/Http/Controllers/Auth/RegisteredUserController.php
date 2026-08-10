<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Register', [
            'locations' => $this->signupLocations(),
        ]);
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $activateNow = (bool) config('hr.activate_signups_immediately');

        $user = User::query()->create([
            ...$request->validated(),
            'role_id' => Role::idFor(Role::STAFF),
            'is_active' => $activateNow,
        ]);

        if (! $activateNow) {
            return redirect()->route('login')->with(
                'status',
                'Your account has been created. An administrator will activate it before you can sign in.',
            );
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    /**
     * Sites a new person may attach themselves to, with enough detail that the
     * choice is obvious rather than a bare name.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function signupLocations(): array
    {
        return Location::query()
            ->openToSignups()
            ->orderBy('name')
            ->get()
            ->map(fn (Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'address' => $location->address,
                'city' => $location->city,
                'work_starts_at' => substr($location->work_starts_at, 0, 5),
                'work_ends_at' => substr($location->work_ends_at, 0, 5),
                'timezone' => $location->timezone,
                'radius_meters' => $location->radius_meters,
            ])
            ->all();
    }
}
