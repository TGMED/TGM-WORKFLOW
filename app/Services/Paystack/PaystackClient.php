<?php

namespace App\Services\Paystack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The two Paystack calls the app makes: the list of Nigerian banks, and the
 * name on an account at one of them.
 */
class PaystackClient
{
    protected const BANKS_KEY = 'paystack.banks';

    /** Kept with no expiry, so a Paystack outage leaves yesterday's list rather than none. */
    protected const LAST_BANKS_KEY = 'paystack.banks.last';

    /**
     * Every bank Paystack can resolve an account at, by name. Cached for a day:
     * banks come and go far less often than people open their profile.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function banks(): array
    {
        return Cache::remember(self::BANKS_KEY, now()->addDay(), function (): array {
            try {
                $response = $this->request()->get('/bank', [
                    'country' => 'nigeria',
                    'currency' => 'NGN',
                    'perPage' => 500,
                ]);
            } catch (ConnectionException) {
                $response = null;
            }

            if ($response === null || ! $response->successful()) {
                return Cache::get(self::LAST_BANKS_KEY, []);
            }

            $banks = collect((array) $response->json('data'))
                ->filter(fn ($bank): bool => is_array($bank) && ($bank['active'] ?? true) && ! empty($bank['code']))
                ->map(fn (array $bank): array => ['code' => (string) $bank['code'], 'name' => trim((string) $bank['name'])])
                ->unique('code')
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();

            Cache::forever(self::LAST_BANKS_KEY, $banks);

            return $banks;
        });
    }

    /**
     * The bank's name for a code, or null when Paystack does not know it.
     */
    public function bankName(string $code): ?string
    {
        foreach ($this->banks() as $bank) {
            if ($bank['code'] === $code) {
                return $bank['name'];
            }
        }

        return null;
    }

    /**
     * The name the bank holds against this account.
     *
     * @throws AccountNotResolved
     */
    public function resolveAccount(string $accountNumber, string $bankCode): string
    {
        try {
            $response = $this->request()->get('/bank/resolve', [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);
        } catch (ConnectionException) {
            throw new AccountNotResolved('We could not reach the bank to check this account. Try again in a minute.');
        }

        $name = $response->json('data.account_name');

        if ($response->successful() && is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        // Paystack answers a number the bank does not recognise with a 422. Anything
        // else is on their side or ours, and says nothing about the account.
        if ($response->status() === 422 || $response->status() === 400) {
            throw new AccountNotResolved('That account number was not found at this bank. Check both and try again.');
        }

        Log::warning('Paystack could not resolve a bank account.', [
            'status' => $response->status(),
            'message' => $response->json('message'),
        ]);

        // A test key allows only a handful of live lookups a day, and a live
        // key is rate limited too. Either way, waiting a minute may not help.
        if ($response->status() === 429) {
            throw new AccountNotResolved('Too many account checks have been made for now. Try again later, or ask HR to check the Paystack key.');
        }

        throw new AccountNotResolved('We could not check this account just now. Try again in a minute.');
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl((string) config('services.paystack.url'))
            ->withToken((string) config('services.paystack.secret'))
            ->acceptJson()
            ->timeout(10);
    }
}
