<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Invitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Following the link in an invitation: choose a password, and you are in.
 */
class InvitationController extends Controller
{
    public function __construct(protected Invitations $invitations) {}

    /**
     * A link that has been used, replaced or has run out says so, rather
     * than a bare 404: the person holding it did nothing wrong, and needs to
     * know to ask for another.
     */
    public function show(string $token): Response
    {
        $user = $this->invitations->find($token);

        return Inertia::render('auth/AcceptInvitation', [
            'token' => $token,
            'valid' => $user !== null,
            'name' => $user?->name,
            'email' => $user?->email,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $user = $this->invitations->find($token);

        abort_if($user === null, 410, 'This invitation has been used or has run out.');

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $this->invitations->accept($user, $validated['password']);

        Auth::login($user);
        $request->session()->regenerate();

        // Straight to the dashboard: anybody with details still to give is
        // turned from there into the guided setup by the profile gate.
        return redirect()->route('dashboard');
    }
}
