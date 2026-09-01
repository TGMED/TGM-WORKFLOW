<?php

namespace App\Support;

/**
 * The Firebase settings the browser needs, and the question of whether push
 * is set up at all. Kept here so the answer is the same everywhere it is
 * asked, and so nothing but the web keys ever reaches the browser.
 */
class Push
{
    /**
     * Whether the browser has everything it needs to ask Firebase for a
     * registration token.
     */
    public static function isConfigured(): bool
    {
        $config = self::browserConfig();

        return $config !== null;
    }

    /**
     * The public Firebase web config, or null when it is incomplete. These
     * keys are safe in the browser: they identify the project, they do not
     * authorise sending.
     *
     * @return array<string, string>|null
     */
    public static function browserConfig(): ?array
    {
        /** @var array<string, string|null> $web */
        $web = config('services.fcm.web', []);

        $required = ['api_key', 'project_id', 'messaging_sender_id', 'app_id', 'vapid_key'];

        foreach ($required as $key) {
            if (blank($web[$key] ?? null)) {
                return null;
            }
        }

        return [
            'apiKey' => (string) $web['api_key'],
            'authDomain' => (string) ($web['auth_domain'] ?? $web['project_id'].'.firebaseapp.com'),
            'projectId' => (string) $web['project_id'],
            'messagingSenderId' => (string) $web['messaging_sender_id'],
            'appId' => (string) $web['app_id'],
            'vapidKey' => (string) $web['vapid_key'],
        ];
    }
}
