<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The reset link, queued.
 *
 * The framework's own is sent inline, which puts a Brevo API call inside the
 * request that asks for the link. Everything else the app sends goes through
 * the queue; this is the one mail that did not, and it is on the slowest path
 * we have — a throttled endpoint a locked-out person is already waiting on.
 *
 * It deliberately does not extend TopicNotification: getting back into your
 * account is not a topic anyone may switch off, and it must go by mail
 * whatever the person's notification settings say.
 */
class ResetPassword extends BaseResetPassword implements ShouldQueue
{
    use Queueable;
}
