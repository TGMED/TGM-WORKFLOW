<?php

namespace App\Http\Controllers;

use App\Support\WhatsNew;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WhatsNewController extends Controller
{
    /**
     * Mark this release's notes as read, so the popup stops appearing on
     * every device rather than just the one it was dismissed on.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['whats_new_seen' => WhatsNew::version()])->save();

        return back();
    }
}
