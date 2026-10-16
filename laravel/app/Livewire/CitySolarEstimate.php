<?php

namespace App\Livewire;

use App\Models\Municipality;
use App\Services\CitySolarService;
use Illuminate\Support\Str;
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
        // Redesign: a quiet text line on the page ground while the lazy estimate loads (no card).
        return <<<'HTML'
<div>
    <section class="vk-seo-solar" aria-label="Aurinkosähkön tuottopotentiaali latautuu">
        <p class="vk-seo-solar-text text-ink-muted">Lasketaan aurinkosähkön tuottopotentiaalia…</p>
    </section>
</div>
HTML;
    }

    public function render()
    {
        $municipality = Municipality::find($this->municipalityId);
        $solarEstimate = null;

        if ($municipality) {
            $citySolarService = app(CitySolarService::class);
            $solarEstimate = $this->isCrawlerRequest()
                ? $citySolarService->getCachedSolarEstimate($municipality)
                : $citySolarService->getSolarEstimate($municipality);
        }

        return view('livewire.city-solar-estimate', [
            'solarEstimate' => $solarEstimate,
        ]);
    }

    private function isCrawlerRequest(): bool
    {
        $userAgent = Str::lower((string) request()->userAgent());

        return Str::contains($userAgent, [
            'bot',
            'crawler',
            'spider',
            'slurp',
            'duckduckbot',
            'baiduspider',
            'yandex',
            'bingpreview',
        ]);
    }
}
