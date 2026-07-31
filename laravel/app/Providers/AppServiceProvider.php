<?php

namespace App\Providers;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\EexMarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use App\Services\CanonicalPricing\PricingMode;
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

        // One scoped value snapshots both flags. All pricing and statistics dependencies in the
        // same request or command therefore use one stable mode.
        $this->app->scoped(PricingMode::class, fn () => PricingMode::fromConfig());

        $this->app->scoped(ResetEstimatorSettings::class, fn ($app) => ResetEstimatorSettings::fromConfig(
            $app->make(PricingMode::class)->resetForwardShiftEnabled(),
        ));

        $this->app->scoped(MarketResetPriceEstimator::class, fn ($app) => new MarketResetPriceEstimator(
            $app->make(MarketReferenceCurveProvider::class),
            $app->make(ResetEstimatorSettings::class),
        ));

        $this->app->scoped(CanonicalContractPriceCalculator::class, fn ($app) => new CanonicalContractPriceCalculator(
            resetEstimator: $app->make(MarketResetPriceEstimator::class),
        ));

        // Keep the orchestrator transient because withSpotAssumptions() stores caller-specific
        // state. Only its immutable mode and calculator dependencies are request-scoped.
        $this->app->bind(CanonicalContractPricingService::class, fn ($app) => new CanonicalContractPricingService(
            calculator: $app->make(CanonicalContractPriceCalculator::class),
            mode: $app->make(PricingMode::class),
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
