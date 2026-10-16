<?php

namespace Tests\Concerns;

use App\Models\ElectricityFuturesEodPrice;

trait CompletesFuturesVintages
{
    /**
     * The market curve provider uses a trade date only when its FI Base curve has month, quarter
     * and year rows. This adds one far-future (2099) row of each type to each fixture date, so the
     * date counts as a complete vintage and no lookup in the test can resolve to a filler row.
     */
    private function completeFuturesVintages(string ...$tradeDates): void
    {
        foreach ($tradeDates as $tradeDate) {
            $exists = ElectricityFuturesEodPrice::query()
                ->where('area', 'FI')
                ->where('maturity', '209901')
                ->whereDate('trade_date', $tradeDate)
                ->exists();

            if ($exists) {
                continue;
            }

            foreach (['month' => 'FNBM', 'quarter' => 'FNBQ', 'year' => 'FNBY'] as $type => $shortCode) {
                ElectricityFuturesEodPrice::create([
                    'exchange' => 'EEX', 'commodity' => 'POWER', 'pricing' => 'F', 'product' => 'Base', 'area' => 'FI',
                    'short_code' => $shortCode, 'maturity' => '209901', 'maturity_type' => $type,
                    'trade_date' => $tradeDate, 'settlement_price' => 50.0,
                ]);
            }
        }
    }
}
