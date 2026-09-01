<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The browsers a person has allowed notifications in.
 *
 * Firebase hands the page a registration token once the person accepts the
 * browser prompt; this is where that token is kept, and where it is dropped
 * when they turn push off again.
 */
class PushTokenController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        // Firebase can hand the same token to a second account on a shared
        // machine, so the token is claimed by whoever registered it last.
        PushToken::query()->updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'last_used_at' => Carbon::now(),
            ],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Push notifications are on for this browser.',
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['nullable', 'string', 'max:512'],
        ]);

        $tokens = PushToken::query()->where('user_id', $request->user()->id);

        // With no token named, every browser this person registered is
        // dropped, which is what "turn push off everywhere" means.
        if (filled($validated['token'] ?? null)) {
            $tokens->where('token', $validated['token']);
        }

        $tokens->delete();

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Push notifications are off.',
        ]);
    }
}
