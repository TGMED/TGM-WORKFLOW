<?php

namespace App\Http\Controllers\Settings;

use App\Enums\NotificationTopic;
use App\Http\Controllers\Controller;
use App\Models\NotificationSetting;
use App\Models\PushToken;
use App\Support\Push;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Notifications', [
            'topics' => NotificationSetting::summaryFor($user),
            'push' => [
                'configured' => Push::isConfigured(),
                'config' => Push::browserConfig(),
                'devices' => PushToken::query()->where('user_id', $user->id)->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'topics' => ['required', 'array'],
            'topics.*.topic' => ['required', 'string', Rule::enum(NotificationTopic::class)],
            'topics.*.email' => ['required', 'boolean'],
            'topics.*.push' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        foreach ($validated['topics'] as $answer) {
            $topic = NotificationTopic::from($answer['topic']);

            // A required topic is not the person's to switch off, so a form
            // that says otherwise is ignored rather than argued with.
            if ($topic->isRequired()) {
                continue;
            }

            NotificationSetting::query()->updateOrCreate(
                ['user_id' => $user->id, 'topic' => $topic->value],
                ['email' => $answer['email'], 'push' => $answer['push']],
            );
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Your notification settings have been saved.',
        ]);
    }
}
