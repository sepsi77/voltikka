<?php

namespace App\Providers;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\EexMarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\CanonicalPricing\SpotForward\SpotForwardPriceEstimator;
use App\Services\CanonicalPricing\SupplierAdjusted\CurrentPriceEpisodeResolver;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedPriceEstimator;
use App\Services\ContractListCacheService;
use DateTimeZone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The curve provider shares its memoized FI forward curves across one request, command,
        // or job. Scoped lifetime clears the available-date list at the next long-lived runtime
        // lifecycle boundary.
        $this->app->scoped(MarketReferenceCurveProvider::class, EexMarketReferenceCurveProvider::class);

        // One scoped value snapshots both flags. All pricing and statistics dependencies in the
        // same request or command therefore use one stable mode.
        $this->app->scoped(PricingMode::class, fn () => PricingMode::fromConfig());

        $this->app->scoped(ContractListCacheService::class);

        $this->app->scoped(ResetEstimatorSettings::class, fn ($app) => ResetEstimatorSettings::fromConfig(
            $app->make(PricingMode::class)->resetForwardShiftEnabled(),
        ));

        $this->app->scoped(MarketResetPriceEstimator::class, fn ($app) => new MarketResetPriceEstimator(
            $app->make(MarketReferenceCurveProvider::class),
            $app->make(ResetEstimatorSettings::class),
        ));

        $this->app->scoped(SupplierAdjustedPriceEstimator::class, fn ($app) => new SupplierAdjustedPriceEstimator(
            $app->make(MarketReferenceCurveProvider::class),
            $app->make(ResetEstimatorSettings::class),
        ));

        $this->app->scoped(SpotForwardPriceEstimator::class, fn ($app) => new SpotForwardPriceEstimator(
            $app->make(MarketReferenceCurveProvider::class),
            $app->make(ResetEstimatorSettings::class),
        ));

        $this->app->scoped(CanonicalContractPriceCalculator::class, fn ($app) => new CanonicalContractPriceCalculator(
            resetEstimator: $app->make(MarketResetPriceEstimator::class),
            supplierAdjustedEstimator: $app->make(SupplierAdjustedPriceEstimator::class),
        ));

        // Keep the orchestrator transient because withSpotAssumptions() stores caller-specific
        // state. Only its immutable mode and calculator dependencies are request-scoped.
        $this->app->bind(CanonicalContractPricingService::class, fn ($app) => new CanonicalContractPricingService(
            calculator: $app->make(CanonicalContractPriceCalculator::class),
            mode: $app->make(PricingMode::class),
            priceEpisodeResolver: $app->make(CurrentPriceEpisodeResolver::class),
            spotEstimator: $app->make(SpotForwardPriceEstimator::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('analytics-events', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('solar-geocode', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            if ($event->task->exitCode === null || $event->task->exitCode === 0) {
                return;
            }

            Log::error('Scheduled task returned a non-zero exit code.', [
                ...$this->scheduledTaskContext($event->task),
                'exit_code' => $event->task->exitCode,
                'runtime_seconds' => $event->runtime,
            ]);
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            Log::error('Scheduled task threw an exception.', [
                ...$this->scheduledTaskContext($event->task),
                'exception_class' => $event->exception::class,
            ]);
        });

        Event::listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event): void {
            if (! $event->task->withoutOverlapping) {
                return;
            }

            Log::error(
                'Scheduled task was skipped because of an overlap lock.',
                $this->scheduledTaskContext($event->task),
            );
        });
    }

    /**
     * @return array{task: string, cron_expression: string, timezone: string|null}
     */
    private function scheduledTaskContext(ScheduledEvent $task): array
    {
        return [
            'task' => $task->getSummaryForDisplay(),
            'cron_expression' => $task->expression,
            'timezone' => $task->timezone instanceof DateTimeZone
                ? $task->timezone->getName()
                : $task->timezone,
        ];
    }
}
