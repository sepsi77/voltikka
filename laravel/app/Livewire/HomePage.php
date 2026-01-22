<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\SpotPriceHour;
use App\Models\SpotPriceQuarter;
use Carbon\Carbon;
use Livewire\Component;

class HomePage extends Component
{
    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';

    public function render()
    {
        return view('livewire.home-page', [
            'contractCount' => ElectricityContract::count(),
            'companyCount' => Company::count(),
            'currentSpotPrice' => $this->getCurrentSpotPrice(),
        ])->layout('layouts.app', [
            'title' => 'Voltikka – Suomen kattavin energiapalvelu',
            'metaDescription' => 'Vertaile sähkösopimuksia, seuraa pörssihintoja, laske aurinkopaneelien tuotto ja löydä paras lämpöpumppu. Kaikki energiapäätökset yhdessä paikassa.',
        ]);
    }

    /**
     * Get the current spot price (15-minute or hourly fallback).
     */
    private function getCurrentSpotPrice(): ?array
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

        return [
            'price_with_tax' => round($price->price_with_tax, 2),
            'price_without_tax' => round($price->price_without_tax, 2),
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
        ];
    }
}
