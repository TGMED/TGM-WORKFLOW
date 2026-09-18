<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The walkthroughs that show somebody around a page the first time they land
 * on it.
 *
 * What each one says lives in the client, since it points at things on the
 * page. What is kept here is only which ones a person has already seen, so
 * they are not shown twice across their devices.
 */
class TourController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tour' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9\-]+$/'],
        ]);

        $user = $request->user();

        $seen = $user->tours_seen ?? [];

        if (! in_array($validated['tour'], $seen, true)) {
            $seen[] = $validated['tour'];

            $user->forceFill(['tours_seen' => $seen])->save();
        }

        return back();
    }

    /**
     * Forget them all, so somebody can be walked through again from the help
     * button rather than having to be told which page to clear.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['tours_seen' => []])->save();

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'The walkthroughs will show again as you go.',
        ]);
    }
}
