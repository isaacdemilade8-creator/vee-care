<?php

namespace App\Providers;

use App\Services\Contracts\AiClinicalAssistant;
use App\Services\NullAiClinicalAssistant;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiClinicalAssistant::class, NullAiClinicalAssistant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
