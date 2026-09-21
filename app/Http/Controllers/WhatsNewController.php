<?php

namespace App\Http\Controllers;

use App\Support\WhatsNew;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WhatsNewController extends Controller
{
    /**
     * Every release, for going back to. Reading this counts as reading the
     * popup, so somebody who follows the link from it is not shown it again.
     */
    public function index(Request $request): Response
    {
        WhatsNew::markSeen($request->user());

        return Inertia::render('WhatsNew', [
            'releases' => WhatsNew::releases(),
        ]);
    }

    /**
     * Mark this release's notes as read, so the popup stops appearing on
     * every device rather than just the one it was dismissed on.
     */
    public function store(Request $request): RedirectResponse
    {
        WhatsNew::markSeen($request->user());

        return back();
    }
}
