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
     * Currently selected municipality slug.
     */
    public ?string $selectedMunicipality = null;

    /**
     * Mount the component with optional location slug.
     */
    public function mount(?string $location = null): void
    {
        if ($location) {
            // Find the municipality by slug
            $postcode = Postcode::where('municipal_name_fi_slug', $location)->first();
            if ($postcode) {
                $this->selectedMunicipality = $postcode->municipal_name_fi;
            }
        }
    }

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
     * Get contracts available in selected municipality.
     */
    public function getContractsProperty(): Collection
    {
        if (!$this->selectedMunicipality) {
            return collect();
        }

        // Get all postcodes in this municipality
        $postcodes = Postcode::where('municipal_name_fi', $this->selectedMunicipality)
            ->pluck('postcode')
            ->toArray();

        if (empty($postcodes)) {
            return collect();
        }

        // Get contracts that are available nationally or in these postcodes
        return \App\Models\ElectricityContract::query()
            ->with(['company', 'priceComponents', 'electricitySource'])
            ->where(function ($query) use ($postcodes) {
                $query->where('availability_is_national', true)
                      ->orWhereExists(function ($subquery) use ($postcodes) {
                          $subquery->selectRaw(1)
                                   ->from('contract_postcode')
                                   ->whereColumn('contract_postcode.contract_id', 'electricity_contracts.id')
                                   ->whereIn('contract_postcode.postcode', $postcodes);
                      });
            })
            ->get();
    }

    /**
     * Select a municipality.
     */
    public function selectMunicipality(string $name, string $slug): void
    {
        $this->selectedMunicipality = $name;
    }

    /**
     * Clear the selected municipality.
     */
    public function clearSelection(): void
    {
        $this->selectedMunicipality = null;
    }

    public function render()
    {
        return view('livewire.locations-list', [
            'municipalities' => $this->municipalities,
            'contracts' => $this->contracts,
        ])->layout('layouts.app', ['title' => $this->selectedMunicipality
            ? "Sähkösopimukset - {$this->selectedMunicipality}"
            : 'Paikkakunnat - Sähkösopimukset paikkakunnittain']);
    }
}
