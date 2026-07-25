<?php

namespace App\Providers;

use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\EexMarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
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
        // The curve provider memoizes one FI forward curve per vintage, so it must be shared
        // across a whole request/command: a listing rebuild asks for 12 delivery months on
        // every reset contract and would otherwise issue hundreds of queries.
        $this->app->singleton(MarketReferenceCurveProvider::class, EexMarketReferenceCurveProvider::class);

        // Settings come from config, never from autowired defaults, otherwise the feature flag
        // would silently read as false.
        $this->app->bind(ResetEstimatorSettings::class, fn () => ResetEstimatorSettings::fromConfig());

        // Deliberately NOT a singleton: the settings are a config snapshot, and a singleton would
        // keep a stale feature-flag value if config changed after first resolution. The expensive
        // per-vintage memoization lives in the shared provider above, so rebuilding is cheap.
        $this->app->bind(MarketResetPriceEstimator::class, fn ($app) => new MarketResetPriceEstimator(
            $app->make(MarketReferenceCurveProvider::class),
            ResetEstimatorSettings::fromConfig(),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('solar-geocode', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
