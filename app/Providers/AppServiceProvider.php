<?php

namespace App\Providers;

use App\Services\JwtService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Login attempts allowed per minute per client IP.
     */
    private const int LOGIN_ATTEMPTS_PER_MINUTE = 5;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(JwtService::class, fn (Application $app): JwtService => new JwtService(
            secret: $app['config']->get('jwt.secret'),
            ttlSeconds: $app['config']->get('jwt.ttl_seconds'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Credential stuffing is cheap to attempt; throttle by IP rather than by
        // email so an attacker cannot lock out a known account.
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(self::LOGIN_ATTEMPTS_PER_MINUTE)
            ->by($request->ip()));
    }
}
