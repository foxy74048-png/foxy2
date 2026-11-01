<?php

namespace App\Platform;

use App\Platform\Context\ContextStore;
use App\Platform\Entitlements\Entitlements;
use App\Platform\Entitlements\MeteredEntitlements;
use App\Platform\Http\ApiError;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ContextStore::class);
        $this->app->bind(Entitlements::class, MeteredEntitlements::class);
    }

    public function boot(): void
    {
        // Visual comum a todos os produtos: <x-core::layout>, <x-core::plain>, <x-core::nav-link>, <x-core::icon>.
        Blade::anonymousComponentPath(resource_path('views/core'), 'core');

        RateLimiter::for('api-client', function (Request $request) {
            $client = $request->attributes->get('api_client');

            return Limit::perMinute(config('foxy.api_rate_limit_per_minute'))
                ->by($client?->getKey() ?? $request->ip())
                ->response(fn () => ApiError::response('rate_limited', 'Muitas requisições. Tente de novo em instantes.', 429));
        });
    }
}
