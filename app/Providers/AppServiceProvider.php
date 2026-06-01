<?php

namespace App\Providers;

use App\Models\Document;
use App\Policies\DocumentPolicy;
use App\Services\AuthService;
use App\Services\CycleService;
use App\Services\DocumentService;
use App\Services\LdapService;
use Illuminate\Support\Facades\Gate;
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
    }
}
