<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('register', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perHour(20)->by($request->ip()),
            ];
        });

        RateLimiter::for('whatsapp', function (Request $request) {
            $tenantId = app()->bound('tenant_id') ? (string) app('tenant_id') : 'no-tenant';
            $userId = (string) ($request->user()?->id ?? $request->ip());

            return Limit::perMinute(20)->by($tenantId . ':' . $userId);
        });

    }
}
