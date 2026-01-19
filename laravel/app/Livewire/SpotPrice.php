<?php

namespace App\Livewire;

use App\Models\SpotPriceHour;
use Carbon\Carbon;
use Livewire\Component;

class SpotPrice extends Component
{
    public array $hourlyPrices = [];
    public bool $loading = true;
    public ?string $error = null;

    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';

    public function mount(): void
    {
        $this->fetchPrices();
    }

    public function fetchPrices(): void
    {
        $this->loading = true;
        $this->error = null;

        try {
            $this->hourlyPrices = $this->loadPricesFromDatabase();
        } catch (\Exception $e) {
            $this->error = 'Hintatietojen lataaminen epäonnistui. Yritä myöhemmin uudelleen.';
        }

        $this->loading = false;
    }

    /**
     * Load prices for today and tomorrow from the database.
     */
    private function loadPricesFromDatabase(): array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $todayStart = $helsinkiNow->copy()->startOfDay()->setTimezone('UTC');
        $tomorrowEnd = $helsinkiNow->copy()->addDay()->endOfDay()->setTimezone('UTC');

        $prices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$todayStart, $tomorrowEnd])
            ->orderBy('utc_datetime')
            ->get();

        return $prices->map(function (SpotPriceHour $price) {
            // Parse as UTC explicitly, then convert to Helsinki
            $utcTime = Carbon::parse($price->utc_datetime)->shiftTimezone('UTC');
            $helsinkiTime = $utcTime->copy()->setTimezone(self::TIMEZONE);

            return [
                'region' => $price->region,
                'timestamp' => $price->timestamp,
                'utc_datetime' => $utcTime->toIso8601String(),
                'price_without_tax' => $price->price_without_tax,
                'vat_rate' => $price->vat_rate,
                'price_with_tax' => round($price->price_with_tax, 2),
                'helsinki_hour' => (int) $helsinkiTime->format('H'),
                'helsinki_date' => $helsinkiTime->format('Y-m-d'),
            ];
        })->toArray();
    }

    /**
     * Get today's prices only (for statistics).
     */
    private function getTodayPrices(): array
    {
        $todayHelsinkiDate = Carbon::now(self::TIMEZONE)->format('Y-m-d');

        return array_filter(
            $this->hourlyPrices,
            fn($price) => $price['helsinki_date'] === $todayHelsinkiDate
        );
    }

    /**
     * Get current hour's spot price.
     */
    public function getCurrentPrice(): ?array
    {
        if (empty($this->hourlyPrices)) {
            return null;
        }

        $currentHour = (int) Carbon::now(self::TIMEZONE)->format('H');
        $todayDate = Carbon::now(self::TIMEZONE)->format('Y-m-d');

        foreach ($this->hourlyPrices as $price) {
            if ($price['helsinki_hour'] === $currentHour && $price['helsinki_date'] === $todayDate) {
                return $price;
            }
        }

        return null;
    }

    /**
     * Get min and max prices for today.
     */
    public function getTodayMinMax(): array
    {
        $todayPrices = $this->getTodayPrices();

        if (empty($todayPrices)) {
            return ['min' => null, 'max' => null];
        }

        $prices = array_column($todayPrices, 'price_without_tax');
        return [
            'min' => min($prices),
            'max' => max($prices),
        ];
    }

    /**
     * Get cheapest hour for today.
     */
    public function getCheapestHour(): ?array
    {
        $todayPrices = $this->getTodayPrices();

        if (empty($todayPrices)) {
            return null;
        }

        $sorted = $todayPrices;
        usort($sorted, fn($a, $b) => $a['price_without_tax'] <=> $b['price_without_tax']);

        return $sorted[0] ?? null;
    }

    /**
     * Get most expensive hour for today.
     */
    public function getMostExpensiveHour(): ?array
    {
        $todayPrices = $this->getTodayPrices();

        if (empty($todayPrices)) {
            return null;
        }

        $sorted = $todayPrices;
        usort($sorted, fn($a, $b) => $b['price_without_tax'] <=> $a['price_without_tax']);

        return $sorted[0] ?? null;
    }

    /**
     * Calculate daily statistics (min, max, average, median).
     */
    public function getTodayStatistics(): array
    {
        $todayPrices = $this->getTodayPrices();

        if (empty($todayPrices)) {
            return [
                'min' => null,
                'max' => null,
                'average' => null,
                'median' => null,
            ];
        }

        $prices = array_column($todayPrices, 'price_without_tax');
        sort($prices);

        $count = count($prices);
        $middle = (int) floor($count / 2);

        // Calculate median
        if ($count % 2 === 0) {
            $median = ($prices[$middle - 1] + $prices[$middle]) / 2;
        } else {
            $median = $prices[$middle];
        }

        return [
            'min' => min($prices),
            'max' => max($prices),
            'average' => array_sum($prices) / $count,
            'median' => $median,
        ];
    }

    public function render()
    {
        return view('livewire.spot-price', [
            'currentPrice' => $this->getCurrentPrice(),
            'todayMinMax' => $this->getTodayMinMax(),
            'cheapestHour' => $this->getCheapestHour(),
            'mostExpensiveHour' => $this->getMostExpensiveHour(),
            'todayStatistics' => $this->getTodayStatistics(),
        ])->layout('layouts.app', ['title' => 'Pörssisähkön hinta - Voltikka']);
    }
}
