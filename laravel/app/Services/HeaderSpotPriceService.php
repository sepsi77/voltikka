<?php

namespace App\Services;

use App\Models\SpotPriceQuarter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class HeaderSpotPriceService
{
    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';
    private const CACHE_TTL_SECONDS = 60;

    public function getCurrentPrice(): ?array
    {
        return Cache::remember('header_spot_price_current', self::CACHE_TTL_SECONDS, function () {
            $helsinkiNow = Carbon::now(self::TIMEZONE);

            return $this->getCurrentQuarterPrice($helsinkiNow);
        });
    }

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

        if (! $price) {
            return null;
        }

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

}
