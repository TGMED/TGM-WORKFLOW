<?php

namespace App\Providers;

use App\Notifications\Channels\FcmChannel;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerBrevoMailer();
        $this->registerRateLimiters();
        $this->registerPushChannel();
    }

    /**
     * Register the application's named rate limiters.
     */
    protected function registerRateLimiters(): void
    {
        // Resets are limited on both axes. The email bucket is the tight one:
        // it stops a single account being hammered from many hosts. The IP
        // bucket is deliberately looser so a shared office NAT, where several
        // staff may reset in the same minute, does not become the binding
        // limit while still capping token guessing from one host.
        RateLimiter::for('reset-password', fn (Request $request): array => [
            Limit::perMinute(5)->by('reset-password|email|'.Str::transliterate(
                Str::lower($request->string('email')->toString()),
            )),
            Limit::perMinute(20)->by('reset-password|ip|'.$request->ip()),
        ]);
    }

    /**
     * Register Firebase Cloud Messaging as a notification channel, so a
     * notification can list `fcm` alongside `mail`.
     */
    protected function registerPushChannel(): void
    {
        Notification::extend('fcm', fn ($app): FcmChannel => $app->make(FcmChannel::class));
    }

    /**
     * Register the Brevo transactional email API as a mail transport.
     */
    protected function registerBrevoMailer(): void
    {
        Mail::extend('brevo', function (array $config): BrevoApiTransport {
            $key = $config['key'] ?? config('services.brevo.key');

            if (blank($key)) {
                throw new \InvalidArgumentException('The Brevo mailer requires an API key. Set BREVO_API_KEY in your environment.');
            }

            return new BrevoApiTransport($key);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // The signup form mirrors these rules as a live checklist, so they must
        // hold in every environment. Only the breach check, which costs an
        // HTTP round trip, stays gated to production.
        Password::defaults(fn (): Password => Password::min(8)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols()
            ->when(
                app()->isProduction(),
                fn (Password $rule): Password => $rule->uncompromised(),
            ),
        );
    }
}
