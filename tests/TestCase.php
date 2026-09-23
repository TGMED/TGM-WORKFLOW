<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every profile page reads the bank list, so it is answered here once
        // rather than reaching Paystack from half the suite. Looking up an
        // account is left for each test to fake, since the answer is the test.
        Http::fake([
            'api.paystack.co/bank?*' => Http::response(['status' => true, 'data' => [
                ['name' => 'Access Bank', 'code' => '044', 'active' => true],
                ['name' => 'Guaranty Trust Bank', 'code' => '058', 'active' => true],
                ['name' => 'Zenith Bank', 'code' => '057', 'active' => true],
            ]]),
        ]);
    }

    /**
     * Paystack answering an account lookup with this name, or turning it away.
     */
    protected function fakeAccountLookup(?string $name): void
    {
        Http::fake([
            'api.paystack.co/bank/resolve*' => $name === null
                ? Http::response(['status' => false, 'message' => 'Could not resolve account name.'], 422)
                : Http::response(['status' => true, 'data' => ['account_number' => '0123456789', 'account_name' => $name]]),
        ]);
    }
}
