<?php

namespace App\Providers;

use App\Services\TenantDatabaseManager;
use App\Services\TenantProvisioner;
use App\Services\TenantResolver;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantDatabaseManager::class);
        $this->app->singleton(TenantResolver::class);
        $this->app->singleton(TenantProvisioner::class);
    }

    public function boot(): void
    {
        //
    }
}
