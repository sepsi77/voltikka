<?php

namespace Tests\Concerns;

use App\Models\ElectricityFuturesEodPrice;

trait SeedsFlatForecastFutures
{
    private function seedFlatForecastFutures(): void
    {
        foreach (range(2025, 2030) as $year) {
            ElectricityFuturesEodPrice::create([
                'exchange' => 'EEX', 'area' => 'FI', 'product' => 'Base', 'short_code' => 'FYBY',
                'maturity_type' => 'year', 'maturity' => $year.'01',
                'trade_date' => '2024-12-01', 'settlement_price' => 50,
            ]);
        }
    }
}
