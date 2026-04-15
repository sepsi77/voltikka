<?php

namespace App\Livewire;

use App\Services\HeaderSpotPriceService;
use Livewire\Component;

/**
 * Lightweight spot price component for the site header.
 * Shows current 15-minute spot price with a link to the full spot price page.
 * Falls back to hourly price if 15-minute data is not available.
 */
class HeaderSpotPrice extends Component
{
    public function getCurrentPrice(): ?array
    {
        return app(HeaderSpotPriceService::class)->getCurrentPrice();
    }

    public function render()
    {
        return view('livewire.header-spot-price', [
            'currentPrice' => $this->getCurrentPrice(),
        ]);
    }
}
