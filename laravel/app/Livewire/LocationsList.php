<?php

namespace App\Livewire;

use App\Models\Postcode;
use Illuminate\Support\Collection;
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

    public function render()
    {
        return view('livewire.locations-list', [
            'municipalities' => $this->municipalities,
        ])->layout('layouts.app', [
            'title' => 'Paikkakunnat - Sähkösopimukset paikkakunnittain',
            'metaDescription' => $this->metaDescription,
        ]);
    }
}
