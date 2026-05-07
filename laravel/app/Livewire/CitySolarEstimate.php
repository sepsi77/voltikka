<?php

namespace App\Livewire;

use App\Models\Municipality;
use App\Services\CitySolarService;
use Livewire\Component;

class CitySolarEstimate extends Component
{
    public int $municipalityId;

    public string $cityLocative;

    public string $citySlug;

    public function mount(int $municipalityId, string $cityLocative, string $citySlug): void
    {
        $this->municipalityId = $municipalityId;
        $this->cityLocative = $cityLocative;
        $this->citySlug = $citySlug;
    }

    public function placeholder(): string
    {
        return <<<'HTML'
<div>
    <section class="bg-gradient-to-r from-amber-50 to-yellow-50 rounded-2xl shadow-sm border border-amber-200 p-6 mb-8" aria-label="Aurinkosähkön tuottopotentiaali latautuu">
        <div class="flex flex-col md:flex-row items-start md:items-center gap-6 animate-pulse">
            <div class="flex-shrink-0">
                <div class="p-4 bg-amber-100 rounded-xl h-16 w-16"></div>
            </div>
            <div class="flex-1 w-full">
                <div class="h-5 bg-amber-100 rounded w-72 max-w-full mb-3"></div>
                <div class="h-4 bg-amber-100 rounded w-full max-w-xl mb-3"></div>
                <div class="h-4 bg-amber-100 rounded w-56 max-w-full"></div>
            </div>
        </div>
    </section>
</div>
HTML;
    }

    public function render()
    {
        $municipality = Municipality::find($this->municipalityId);
        $solarEstimate = $municipality
            ? app(CitySolarService::class)->getSolarEstimate($municipality)
            : null;

        return view('livewire.city-solar-estimate', [
            'solarEstimate' => $solarEstimate,
        ]);
    }
}
