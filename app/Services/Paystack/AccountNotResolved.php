<?php

namespace App\Services\Paystack;

use RuntimeException;

/**
 * Paystack could not say whose account this is. The message is written for the
 * person filling in the form.
 */
class AccountNotResolved extends RuntimeException {}
