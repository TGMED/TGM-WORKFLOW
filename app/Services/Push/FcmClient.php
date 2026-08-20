<?php

namespace App\Services\Push;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Talks to Firebase Cloud Messaging's v1 API.
 *
 * The v1 API wants an OAuth access token rather than the old server key, so
 * this signs a JWT with the service account's private key and swaps it for
 * one. That is the whole of Google's auth dance for a server-to-server call,
 * which is why there is no SDK here.
 */
class FcmClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const CACHE_KEY = 'fcm.access_token';

    /**
     * Whether enough of the service account is configured to send anything.
     * Everything here is a no-op until it is, so a developer machine with no
     * Firebase project behaves as though nobody has push switched on.
     */
    public function isConfigured(): bool
    {
        return filled($this->config('project_id'))
            && filled($this->config('client_email'))
            && filled($this->config('private_key'));
    }

    /**
     * Send one message to one registration token.
     *
     * Returns false when FCM says the token is dead, which is the caller's cue
     * to delete it. Any other failure is logged and swallowed: a notification
     * that cannot be pushed must not take the request down with it.
     */
    public function send(string $token, PushMessage $message): bool
    {
        if (! $this->isConfigured()) {
            return true;
        }

        $accessToken = $this->accessToken();

        if ($accessToken === null) {
            return true;
        }

        $url = sprintf(
            'https://fcm.googleapis.com/v1/projects/%s/messages:send',
            $this->config('project_id'),
        );

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, ['message' => $this->payload($token, $message)]);
        } catch (ConnectionException $e) {
            Log::warning('Could not reach FCM.', ['error' => $e->getMessage()]);

            return true;
        }

        if ($response->successful()) {
            return true;
        }

        // 404 means the token no longer maps to an install; 403 means it was
        // never ours. Either way it will never work again.
        if (in_array($response->status(), [403, 404], true)) {
            return false;
        }

        Log::warning('FCM refused a message.', [
            'status' => $response->status(),
            'body' => $response->json('error.message') ?? $response->body(),
        ]);

        return true;
    }

    /**
     * The FCM v1 message body. The notification block is what the browser
     * shows; the data block is what the service worker reads when the person
     * clicks it.
     *
     * @return array<string, mixed>
     */
    protected function payload(string $token, PushMessage $message): array
    {
        $data = array_map(
            fn (string $value): string => $value,
            [...$message->data, ...$message->url === null ? [] : ['url' => $message->url]],
        );

        return [
            'token' => $token,
            'notification' => [
                'title' => $message->title,
                'body' => $message->body,
            ],
            'data' => $data,
            'webpush' => [
                'notification' => [
                    'icon' => '/apple-touch-icon.png',
                    'badge' => '/favicon-32.png',
                ],
                'fcm_options' => array_filter(['link' => $message->url]),
            ],
        ];
    }

    /**
     * A bearer token for the messaging scope, cached just short of the hour
     * Google issues it for.
     */
    protected function accessToken(): ?string
    {
        /** @var string|null $token */
        $token = Cache::remember(self::CACHE_KEY, 3300, function (): ?string {
            try {
                $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $this->assertion(),
                ]);
            } catch (ConnectionException|RuntimeException $e) {
                Log::warning('Could not get an FCM access token.', ['error' => $e->getMessage()]);

                return null;
            }

            if (! $response->successful()) {
                Log::warning('FCM refused the service account.', ['body' => $response->body()]);

                return null;
            }

            return $response->json('access_token');
        });

        // Nothing is gained by holding on to a failure.
        if ($token === null) {
            Cache::forget(self::CACHE_KEY);
        }

        return $token;
    }

    /**
     * The signed JWT that stands in for the service account.
     */
    protected function assertion(): string
    {
        $now = Carbon::now()->getTimestamp();

        $header = $this->base64(['alg' => 'RS256', 'typ' => 'JWT']);
        $claims = $this->base64([
            'iss' => $this->config('client_email'),
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $signature = '';
        $key = str_replace('\n', "\n", (string) $this->config('private_key'));

        if (! openssl_sign("{$header}.{$claims}", $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The FCM private key could not sign the request.');
        }

        return "{$header}.{$claims}.".$this->encode($signature);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function base64(array $value): string
    {
        return $this->encode((string) json_encode($value));
    }

    protected function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function config(string $key): ?string
    {
        /** @var string|null $value */
        $value = config("services.fcm.{$key}");

        return $value;
    }
}
