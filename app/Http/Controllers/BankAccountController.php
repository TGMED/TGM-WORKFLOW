<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResolveBankAccountRequest;
use App\Services\Paystack\AccountNotResolved;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\JsonResponse;

/**
 * The name on a bank account, asked for as somebody types, so they can see it
 * is the right one before they save. Whatever saves the account asks again
 * rather than trusting this.
 */
class BankAccountController extends Controller
{
    public function resolve(ResolveBankAccountRequest $request, PaystackClient $paystack): JsonResponse
    {
        try {
            $name = $paystack->resolveAccount(
                $request->string('account_number')->toString(),
                $request->string('bank_code')->toString(),
            );
        } catch (AccountNotResolved $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['account_name' => $name]);
    }
}
