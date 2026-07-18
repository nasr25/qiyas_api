<?php

namespace App\Providers;

use App\Events\WorkflowNotificationRequested;
use App\Listeners\LogEmailSending;
use App\Listeners\LogEmailSent;
use App\Listeners\SendWorkflowNotification;
use App\Models\Document;
use App\Models\EmailLog;
use App\Policies\DocumentPolicy;
use App\Services\AuthService;
use App\Services\CycleService;
use App\Services\DocumentService;
use App\Services\LdapService;
use App\Services\SmtpSettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind services as singletons
        $this->app->singleton(LdapService::class);
        $this->app->singleton(AuthService::class, fn ($app) => new AuthService($app->make(LdapService::class)));
        $this->app->singleton(DocumentService::class);
        $this->app->singleton(CycleService::class);
    }

    public function boot(): void
    {
        $this->configureRateLimiting();

        Gate::policy(Document::class, DocumentPolicy::class);

        // Super Admin bypasses all authorization
        Gate::before(function ($user, $ability) {
            if ($user->hasRole('super-admin')) {
                return true;
            }
        });

        $this->applyMailSettings();

        // Track every outgoing email (sent / failed) for the Super Admin.
        Event::listen(MessageSending::class, LogEmailSending::class);
        Event::listen(MessageSent::class, LogEmailSent::class);

        // Workflow/extension services publish this event instead of calling
        // NotificationService directly — see docs/notification-engine.md.
        Event::listen(WorkflowNotificationRequested::class, SendWorkflowNotification::class);

        // A failed queued mail job marks the most recent pending email failed.
        Queue::failing(function ($event) {
            try {
                $name = method_exists($event->job, 'resolveName') ? $event->job->resolveName() : '';
                if (! str_contains($name, 'Notification') && ! str_contains($name, 'Mail')) {
                    return;
                }

                $log = EmailLog::where('status', 'pending')->latest()->first();
                $log?->update(['status' => 'failed', 'error' => $event->exception?->getMessage()]);
            } catch (\Throwable $e) {
                // ignore
            }
        });
    }

    /**
     * Rate limits for the unauthenticated credential-testing endpoints
     * (login, quick-login). Keyed by IP + the submitted username so a
     * distributed brute force against many accounts from one IP is caught
     * without punishing a shared-NAT office trying one account too many
     * times. Applied via `throttle:login` in routes/api.php.
     *
     * The per-minute limit is configurable (LOGIN_RATE_LIMIT_PER_MINUTE,
     * default 10) rather than hardcoded — an automated E2E suite driving
     * Quick Login dozens of times per minute for the same handful of test
     * accounts (see docs/playwright-e2e-guide.md) legitimately needs a
     * higher ceiling than a production login form does. The E2E backend
     * sets a higher value explicitly; production is unaffected and keeps
     * the same default of 10 as before this became configurable.
     */
    private function configureRateLimiting(): void
    {
        $perMinute = (int) env('LOGIN_RATE_LIMIT_PER_MINUTE', 10);

        RateLimiter::for('login', function (Request $request) use ($perMinute) {
            $key = strtolower((string) $request->input('username')).'|'.$request->ip();

            return Limit::perMinute($perMinute)->by($key);
        });
    }

    /**
     * Override the SMTP mailer config from the DB Settings (the admin SMTP tab),
     * so email works without editing .env. Only applies when a host is set.
     */
    /**
     * Applies the Super-Admin-managed, encrypted-at-rest SMTP
     * configuration (smtp_settings table) to the runtime mail config —
     * see SmtpSettingsService::applyToRuntimeConfig(). Replaces a prior
     * mechanism that read an SMTP password from the generic, unencrypted
     * `settings` key-value table; that table never actually held a
     * password in any environment this platform has run in, but the
     * mechanism itself was a latent plaintext-secret-storage risk and is
     * removed rather than hardened in place. See
     * docs/security/smtp-security.md.
     */
    private function applyMailSettings(): void
    {
        try {
            if (! Schema::hasTable('smtp_settings')) {
                return;
            }

            app(SmtpSettingsService::class)->applyToRuntimeConfig();
        } catch (\Throwable $e) {
            // Never let mail config break booting (e.g. before migrations run).
        }
    }
}
