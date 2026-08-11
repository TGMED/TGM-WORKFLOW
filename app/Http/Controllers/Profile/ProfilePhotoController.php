<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfilePhotoController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [
            'photo.max' => 'Pick a photo under 4 MB.',
            'photo.mimes' => 'Photos must be a JPG, PNG or WebP.',
        ]);

        $path = $request->file('photo')->store('avatars', 'public');

        // The disk can refuse the write. Say so rather than storing `false`
        // and leaving a broken image behind.
        if ($path === false) {
            return back()->withErrors([
                'photo' => 'That photo could not be saved. Please try again.',
            ]);
        }

        $profile = $request->user()->profile()->firstOrNew();

        $previous = $profile->avatar_path;

        $profile->avatar_path = $path;
        $profile->save();

        // Only once the new one is safely written, so a failed upload cannot
        // leave someone with no photo at all.
        $this->forget($previous);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Your photo has been updated.',
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $profile = $request->user()->profile;

        if ($profile?->avatar_path !== null) {
            $this->forget($profile->avatar_path);

            $profile->update(['avatar_path' => null]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Your photo has been removed.',
        ]);
    }

    protected function forget(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('public')->delete($path);
        }
    }
}
