<?php

namespace App\Services\CanonicalPricing\SpotForward\DTO;

use App\Services\CanonicalPricing\SpotForward\Enums\SpotEstimateBasis;

readonly class SpotEstimate
{
    /**
     * @param  array<string, array{base_price: float, day_price: float, night_price: float, source_kind: string, trade_date: string}>  $months
     * @param  list<string>  $flags
     */
    public function __construct(
        public SpotEstimateBasis $basis,
        public ?float $shapeOverallCentsPerKwh,
        public ?float $shapeDayCentsPerKwh,
        public ?float $shapeNightCentsPerKwh,
        public ?float $dayOffsetCentsPerKwh,
        public ?float $nightOffsetCentsPerKwh,
        public ?string $shapePeriodStart,
        public ?string $shapePeriodEnd,
        public ?string $currentCurveTradeDate,
        public ?string $futureCurveTradeDate,
        public array $months,
        public ?float $annualEquivalentBaseCentsPerKwh,
        public ?float $annualEquivalentDayCentsPerKwh,
        public ?float $annualEquivalentNightCentsPerKwh,
        public string $confidence,
        public array $flags = [],
    ) {}

    public function wholesaleForBucket(string $monthKey, string $bucket): ?float
    {
        $month = $this->months[$monthKey] ?? null;
        if ($month !== null) {
            return $bucket === 'NightTime' ? $month['night_price'] : $month['day_price'];
        }

        return $bucket === 'NightTime'
            ? $this->annualEquivalentNightCentsPerKwh
            : $this->annualEquivalentDayCentsPerKwh;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $months = [];
        foreach ($this->months as $month => $values) {
            $months[] = ['month' => $month, ...$values];
        }

        return [
            'basis' => $this->basis->value,
            'shape' => [
                'overall_price' => $this->shapeOverallCentsPerKwh,
                'day_price' => $this->shapeDayCentsPerKwh,
                'night_price' => $this->shapeNightCentsPerKwh,
                'day_offset' => $this->dayOffsetCentsPerKwh,
                'night_offset' => $this->nightOffsetCentsPerKwh,
                'period_start' => $this->shapePeriodStart,
                'period_end' => $this->shapePeriodEnd,
            ],
            'current_curve_trade_date' => $this->currentCurveTradeDate,
            'future_curve_trade_date' => $this->futureCurveTradeDate,
            'months' => $months,
            'annual_equivalent_base_price' => $this->annualEquivalentBaseCentsPerKwh,
            'annual_equivalent_day_price' => $this->annualEquivalentDayCentsPerKwh,
            'annual_equivalent_night_price' => $this->annualEquivalentNightCentsPerKwh,
            'confidence' => $this->confidence,
            'higher_confidence' => $this->basis->isForward(),
            'flags' => $this->flags,
        ];
    }
}
