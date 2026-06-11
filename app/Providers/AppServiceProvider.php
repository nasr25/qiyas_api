<?php

namespace App\Providers;

use App\Listeners\LogEmailSending;
use App\Listeners\LogEmailSent;
use App\Models\Document;
use App\Models\EmailLog;
use App\Models\Setting;
use App\Policies\DocumentPolicy;
use App\Services\AuthService;
use App\Services\CycleService;
use App\Services\DocumentService;
use App\Services\LdapService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind services as singletons
        $this->app->singleton(LdapService::class);
        $this->app->singleton(AuthService::class, fn($app) => new AuthService($app->make(LdapService::class)));
        $this->app->singleton(DocumentService::class);
        $this->app->singleton(CycleService::class);
    }

    public function boot(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);

        // Super Admin bypasses all authorization
        Gate::before(function ($user, $ability) {
            if ($user->hasRole('super-admin')) return true;
        });

        $this->applyMailSettings();

        // Track every outgoing email (sent / failed) for the Super Admin.
        Event::listen(MessageSending::class, LogEmailSending::class);
        Event::listen(MessageSent::class, LogEmailSent::class);

        // A failed queued mail job marks the most recent pending email failed.
        Queue::failing(function ($event) {
            try {
                $name = method_exists($event->job, 'resolveName') ? $event->job->resolveName() : '';
                if (! str_contains($name, 'Notification') && ! str_contains($name, 'Mail')) return;

                $log = EmailLog::where('status', 'pending')->latest()->first();
                $log?->update(['status' => 'failed', 'error' => $event->exception?->getMessage()]);
            } catch (\Throwable $e) {
                // ignore
            }
        });
    }

    /**
     * Override the SMTP mailer config from the DB Settings (the admin SMTP tab),
     * so email works without editing .env. Only applies when a host is set.
     */
    private function applyMailSettings(): void
    {
        try {
            if (! Schema::hasTable('settings')) return;

            $host = Setting::get('smtp', 'host');
            if (! $host) return;

            config([
                'mail.default'                  => 'smtp',
                'mail.mailers.smtp.host'        => $host,
                'mail.mailers.smtp.port'        => (int) Setting::get('smtp', 'port', 587),
                'mail.mailers.smtp.username'    => Setting::get('smtp', 'username'),
                'mail.mailers.smtp.password'    => Setting::get('smtp', 'password'),
                'mail.mailers.smtp.encryption'  => Setting::get('smtp', 'encryption') ?: null,
                'mail.from.address'             => Setting::get('smtp', 'from_address') ?: config('mail.from.address'),
            ]);
        } catch (\Throwable $e) {
            // Never let mail config break booting (e.g. before migrations run).
        }
    }
}
