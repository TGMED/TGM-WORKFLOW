<?php

namespace App\Http\Controllers;

use App\Services\BreakService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BreakController extends Controller
{
    public function __construct(protected BreakService $breaks) {}

    public function store(Request $request, string $action): RedirectResponse
    {
        $result = $action === 'start'
            ? $this->breaks->start($request->user())
            : $this->breaks->end($request->user());

        return back()->with('toast', [
            'type' => $result['ok'] ? 'success' : 'error',
            'message' => $result['message'],
        ]);
    }
}
