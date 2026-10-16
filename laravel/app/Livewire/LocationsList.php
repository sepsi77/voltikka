<?php

namespace App\Livewire;

use App\Http\Middleware\SetPublicCacheHeaders;
use App\Models\Postcode;
use App\Support\Season;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

class LocationsList extends Component
{
    /**
     * Search query for filtering municipalities.
     */
    #[Url]
    public string $search = '';

    /**
     * Get all unique municipalities with their counts.
     */
    public function getMunicipalitiesProperty(): Collection
    {
        $query = Postcode::query()
            ->selectRaw('municipal_name_fi, municipal_name_fi_slug, COUNT(*) as postcode_count')
            ->groupBy('municipal_name_fi', 'municipal_name_fi_slug')
            ->orderBy('municipal_name_fi');

        if ($this->search !== '') {
            $query->where('municipal_name_fi', 'like', "%{$this->search}%");
        }

        return $query->get();
    }

    /**
     * Get meta description for SEO.
     */
    public function getMetaDescriptionProperty(): string
    {
        return 'Selaa sähkösopimuksia paikkakunnittain. Vertaile hintoja ja löydä paras sähkösopimus omalle alueellesi.';
    }

    /**
     * Municipalities grouped by initial letter for the A–Ö index (presentation only, Paper
     * board 887-0). Å/Ä/Ö group under A/O, like the database collation that orders the list.
     *
     * @return array<string, Collection>
     */
    private function letterGroups(Collection $municipalities): array
    {
        $groups = $municipalities
            ->groupBy(fn ($municipality) => Str::upper(Str::ascii(mb_substr((string) $municipality->municipal_name_fi, 0, 1))))
            ->all();
        ksort($groups);

        return $groups;
    }

    public function render()
    {
        $this->enableBackButtonCache();

        $municipalities = $this->municipalities;

        return view('livewire.locations-list', [
            'municipalities' => $municipalities,
            'letterGroups' => $this->letterGroups($municipalities),
        ])->layout('layouts.app', [
            'title' => 'Paikkakunnat - Sähkösopimukset paikkakunnittain',
            'metaDescription' => $this->metaDescription,
            'heroTone' => Season::heroTone('map-finland'),
        ])->response(function ($response) {
            app(SetPublicCacheHeaders::class)->applyCacheHeaders($response);
        });
    }
}
