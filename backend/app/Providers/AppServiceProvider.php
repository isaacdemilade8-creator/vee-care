<?php

namespace App\Providers;

use App\Services\Contracts\AiClinicalAssistant;
use App\Services\NullAiClinicalAssistant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureRateLimiters();
    }

    /**
     * Dedicated rate limiters for public, unauthenticated endpoints.
     *
     * These share a caller-agnostic key so that an anonymous client cannot
     * abuse the onboarding form, the tenant registration endpoint or the
     * invitation-acceptance endpoint. Authenticated routes keep their own
     * middleware-level throttle (see routes/api.php).
     */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('hospital-applications', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('auth-register', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('auth-login', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('invitation-accept', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('platform-user-invite', fn (Request $request): Limit => Limit::perMinute(10)->by($request->user()?->id ?? $request->ip()));

        RateLimiter::for('admin-user-invite', fn (Request $request): Limit => Limit::perMinute(10)->by($request->user()?->id ?? $request->ip()));
    }
}
