<?php

namespace App\Livewire;

use App\Models\SpotPriceHour;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Lightweight spot price component for the site header.
 * Shows current hour's spot price with a link to the full spot price page.
 */
class HeaderSpotPrice extends Component
{
    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';

    public function getCurrentPrice(): ?array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
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
        ];
    }

    public function render()
    {
        return view('livewire.header-spot-price', [
            'currentPrice' => $this->getCurrentPrice(),
        ]);
    }
}
