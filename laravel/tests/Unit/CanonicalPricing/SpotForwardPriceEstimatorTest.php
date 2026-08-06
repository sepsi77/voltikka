<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\SpotForward\Enums\SpotEstimateBasis;
use App\Services\CanonicalPricing\SpotForward\SpotForwardPriceEstimator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SpotForwardPriceEstimatorTest extends TestCase
{
    private CarbonImmutable $start;

    private SpotAssumptions $shape;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = CarbonImmutable::parse('2026-08-06', 'Europe/Helsinki');
        $this->shape = new SpotAssumptions(
            dayAvgWithTax: 7.0,
            nightAvgWithTax: 3.0,
            overallAvgWithTax: 5.0,
            periodStart: CarbonImmutable::parse('2025-08-07', 'UTC'),
            periodEnd: CarbonImmutable::parse('2026-08-06', 'UTC'),
        );
    }

    public function test_it_builds_all_thirteen_touched_months_with_two_deliberate_vintages(): void
    {
        $curve = new FakeSpotForwardCurve;
        $estimate = $this->estimator($curve)->estimate($this->start, $this->shape);

        $this->assertSame(SpotEstimateBasis::ForwardCurve, $estimate->basis);
        $this->assertCount(13, $estimate->months);
        $this->assertSame('2026-08', array_key_first($estimate->months));
        $this->assertSame('2027-08', array_key_last($estimate->months));
        $this->assertSame('2026-07-31', $estimate->currentCurveTradeDate);
        $this->assertSame('2026-08-05', $estimate->futureCurveTradeDate);
        $this->assertSame('2026-08-01', $curve->forwardCalls[0]['as_of']);
        $this->assertSame('2026-08-06', $curve->forwardCalls[1]['as_of']);
        $this->assertSame(['2026-08-01', '2026-08-06'], $curve->tradeDateCalls);
    }

    public function test_it_preserves_additive_intraday_shape_and_month_quarter_year_sources(): void
    {
        $curve = new FakeSpotForwardCurve;
        $curve->kinds = [
            '2026-08' => 'month',
            '2026-09' => 'quarter',
            '2026-10' => 'year',
        ];

        $estimate = $this->estimator($curve)->estimate($this->start, $this->shape);

        $this->assertSame(2.0, $estimate->dayOffsetCentsPerKwh);
        $this->assertSame(-2.0, $estimate->nightOffsetCentsPerKwh);
        $this->assertSame(12.0, $estimate->months['2026-08']['day_price']);
        $this->assertSame(8.0, $estimate->months['2026-08']['night_price']);
        $this->assertSame('month', $estimate->months['2026-08']['source_kind']);
        $this->assertSame('quarter', $estimate->months['2026-09']['source_kind']);
        $this->assertSame('year', $estimate->months['2026-10']['source_kind']);
        $this->assertContains('forward_month_from_quarter_contract', $estimate->flags);
        $this->assertContains('forward_month_from_year_contract', $estimate->flags);
    }

    public function test_it_floors_each_projected_wholesale_bucket_at_zero(): void
    {
        $curve = new FakeSpotForwardCurve(price: 1.0);
        $shape = new SpotAssumptions(
            dayAvgWithTax: 4.0,
            nightAvgWithTax: -4.0,
            overallAvgWithTax: 2.0,
            periodStart: CarbonImmutable::parse('2025-08-07', 'UTC'),
            periodEnd: CarbonImmutable::parse('2026-08-06', 'UTC'),
        );

        $estimate = $this->estimator($curve)->estimate($this->start, $shape);

        $this->assertSame(3.0, $estimate->months['2026-08']['day_price']);
        $this->assertSame(0.0, $estimate->months['2026-08']['night_price']);
        $this->assertSame(0.0, $estimate->annualEquivalentNightCentsPerKwh);
    }

    public function test_one_missing_month_rejects_the_whole_strip_and_returns_the_rolling_payload(): void
    {
        $curve = new FakeSpotForwardCurve;
        $curve->missingMonth = '2027-01';

        $estimate = $this->estimator($curve)->estimate($this->start, $this->shape);

        $this->assertRollingFallback($estimate);
        $this->assertContains('missing_forward_month_2027_01', $estimate->flags);
    }

    public function test_missing_or_stale_vintages_return_the_whole_strip_fallback(): void
    {
        $missing = new FakeSpotForwardCurve;
        $missing->missingCurrentVintage = true;
        $missingEstimate = $this->estimator($missing)->estimate($this->start, $this->shape);
        $this->assertRollingFallback($missingEstimate);
        $this->assertContains('missing_current_curve_vintage', $missingEstimate->flags);

        $stale = new FakeSpotForwardCurve;
        $stale->futureTradeDate = '2026-07-01';
        $staleEstimate = $this->estimator($stale)->estimate($this->start, $this->shape);
        $this->assertRollingFallback($staleEstimate);
        $this->assertContains('stale_future_curve_vintage', $staleEstimate->flags);
    }

    public function test_stale_or_incomplete_shape_returns_the_typed_fallback_without_querying_the_curve(): void
    {
        $staleCurve = new FakeSpotForwardCurve;
        $stale = new SpotAssumptions(
            7.0,
            3.0,
            5.0,
            CarbonImmutable::parse('2025-07-17'),
            CarbonImmutable::parse('2026-07-16'),
        );
        $staleEstimate = $this->estimator($staleCurve)->estimate($this->start, $stale);

        $this->assertRollingFallback($staleEstimate);
        $this->assertContains('stale_shape_period', $staleEstimate->flags);
        $this->assertSame([], $staleCurve->tradeDateCalls);

        $incompleteCurve = new FakeSpotForwardCurve;
        $incomplete = new SpotAssumptions(
            7.0,
            3.0,
            5.0,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-08-06'),
        );
        $incompleteEstimate = $this->estimator($incompleteCurve)->estimate($this->start, $incomplete);

        $this->assertRollingFallback($incompleteEstimate);
        $this->assertContains('incomplete_shape_period', $incompleteEstimate->flags);
        $this->assertSame([], $incompleteCurve->tradeDateCalls);
    }

    public function test_invalid_shape_returns_a_typed_rolling_fallback_without_querying_the_curve(): void
    {
        $curve = new FakeSpotForwardCurve;
        $shape = new SpotAssumptions(7.0, 3.0, 5.0);

        $estimate = $this->estimator($curve)->estimate($this->start, $shape);

        $this->assertRollingFallback($estimate);
        $this->assertContains('invalid_shape_period', $estimate->flags);
        $this->assertSame([], $curve->tradeDateCalls);
        $this->assertSame([], $curve->forwardCalls);
    }

    private function estimator(FakeSpotForwardCurve $curve): SpotForwardPriceEstimator
    {
        return new SpotForwardPriceEstimator($curve, new ResetEstimatorSettings(maxCurveAgeDays: 14));
    }

    private function assertRollingFallback($estimate): void
    {
        $this->assertSame(SpotEstimateBasis::Rolling365Fallback, $estimate->basis);
        $this->assertSame([], $estimate->months);
        $this->assertNull($estimate->currentCurveTradeDate);
        $this->assertNull($estimate->futureCurveTradeDate);
        $this->assertSame(7.0, $estimate->annualEquivalentDayCentsPerKwh);
        $this->assertSame(3.0, $estimate->annualEquivalentNightCentsPerKwh);
        $this->assertSame('fallback', $estimate->confidence);
        $this->assertContains('rolling_365_fallback', $estimate->flags);
    }
}

class FakeSpotForwardCurve implements MarketReferenceCurveProvider
{
    /** @var list<string> */
    public array $tradeDateCalls = [];

    /** @var list<array{as_of:string,month:string}> */
    public array $forwardCalls = [];

    /** @var array<string, string> */
    public array $kinds = [];

    public ?string $missingMonth = null;

    public bool $missingCurrentVintage = false;

    public string $futureTradeDate = '2026-08-05';

    public function __construct(public float $price = 10.0) {}

    public function tradeDate(CarbonImmutable $asOfDate): ?CarbonImmutable
    {
        $date = $asOfDate->toDateString();
        $this->tradeDateCalls[] = $date;

        if ($date === '2026-08-01') {
            return $this->missingCurrentVintage ? null : CarbonImmutable::parse('2026-07-31');
        }

        return CarbonImmutable::parse($this->futureTradeDate);
    }

    public function referencePrice(CarbonImmutable $asOfDate, CarbonImmutable $anchorMonth, array $kindPreference): ?array
    {
        return null;
    }

    public function forwardPriceForMonth(CarbonImmutable $asOfDate, CarbonImmutable $deliveryMonth): ?array
    {
        $month = $deliveryMonth->format('Y-m');
        $this->forwardCalls[] = ['as_of' => $asOfDate->toDateString(), 'month' => $month];

        if ($month === $this->missingMonth) {
            return null;
        }

        return [
            'kind' => $this->kinds[$month] ?? 'month',
            'price_cents_per_kwh' => $this->price,
        ];
    }

    public function spotSeasonalIndex(): ?array
    {
        return null;
    }

    public function fixedTermMedianEnergyPrice(): ?float
    {
        return null;
    }
}
