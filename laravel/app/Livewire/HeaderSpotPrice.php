<?php

namespace App\Livewire;

use App\Models\SpotPriceHour;
use App\Models\SpotPriceQuarter;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Lightweight spot price component for the site header.
 * Shows current 15-minute spot price with a link to the full spot price page.
 * Falls back to hourly price if 15-minute data is not available.
 */
class HeaderSpotPrice extends Component
{
    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';

    public function getCurrentPrice(): ?array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);

        // Try to get 15-minute price first
        $quarterPrice = $this->getCurrentQuarterPrice($helsinkiNow);
        if ($quarterPrice) {
            return $quarterPrice;
        }

        // Fall back to hourly price
        return $this->getCurrentHourlyPrice($helsinkiNow);
    }

    /**
     * Get the current 15-minute interval price.
     */
    private function getCurrentQuarterPrice(Carbon $helsinkiNow): ?array
    {
        // Calculate current 15-minute interval start
        $minute = (int) $helsinkiNow->format('i');
        $quarterMinute = (int) floor($minute / 15) * 15;
        $quarterStart = $helsinkiNow->copy()->minute($quarterMinute)->second(0)->setTimezone('UTC');
        $quarterEnd = $quarterStart->copy()->addMinutes(15);

        $price = SpotPriceQuarter::forRegion(self::REGION)
            ->where('utc_datetime', '>=', $quarterStart)
            ->where('utc_datetime', '<', $quarterEnd)
            ->first();

        if (!$price) {
            return null;
        }

        // Calculate which quarter of the hour (0-3)
        $quarterIndex = (int) floor($minute / 15);
        $quarterStartMinute = $quarterIndex * 15;
        $quarterEndMinute = $quarterStartMinute + 15;

        return [
            'price_with_tax' => round($price->price_with_tax, 2),
            'price_without_tax' => round($price->price_without_tax, 2),
            'is_quarter' => true,
            'time_label' => sprintf(
                '%s:%02d-%s:%02d',
                $helsinkiNow->format('H'),
                $quarterStartMinute,
                $quarterEndMinute === 60 ? $helsinkiNow->copy()->addHour()->format('H') : $helsinkiNow->format('H'),
                $quarterEndMinute === 60 ? 0 : $quarterEndMinute
            ),
        ];
    }

    /**
     * Get the current hourly price (fallback).
     */
    private function getCurrentHourlyPrice(Carbon $helsinkiNow): ?array
    {
        $currentHour = (int) $helsinkiNow->format('H');
        $todayStart = $helsinkiNow->copy()->startOfDay()->setTimezone('UTC');
        $todayEnd = $helsinkiNow->copy()->endOfDay()->setTimezone('UTC');

        $price = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$todayStart, $todayEnd])
            ->get()
            ->first(function (SpotPriceHour $price) use ($currentHour) {
                $helsinkiTime = Carbon::parse($price->utc_datetime)
                    ->shiftTimezone('UTC')
                    ->setTimezone(self::TIMEZONE);
                return (int) $helsinkiTime->format('H') === $currentHour;
            });

        if (!$price) {
            return null;
        }

        return [
            'price_with_tax' => round($price->price_with_tax, 2),
            'price_without_tax' => round($price->price_without_tax, 2),
            'is_quarter' => false,
            'time_label' => sprintf('%s:00-%s:00', $helsinkiNow->format('H'), $helsinkiNow->copy()->addHour()->format('H')),
        ];
    }

    public function render()
    {
        return view('livewire.header-spot-price', [
            'currentPrice' => $this->getCurrentPrice(),
        ]);
    }
}
