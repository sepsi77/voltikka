<?php

namespace App\Livewire;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Component;

class LocalContractsSection extends Component
{
    /**
     * The city name (e.g., "Helsinki").
     */
    public string $cityName;

    /**
     * The city name in locative form (e.g., "Helsingissä").
     */
    public string $cityLocative;

    /**
     * Annual consumption in kWh.
     */
    public int $consumption = 5000;

    /**
     * Contracts from local/nearby companies (passed from parent).
     */
    public Collection $localCompanyContracts;

    /**
     * Regional contracts available in the area (passed from parent).
     */
    public Collection $regionalContracts;

    /**
     * Mount the component.
     */
    public function mount(
        string $cityName,
        string $cityLocative,
        int $consumption,
        Collection $localCompanyContracts,
        Collection $regionalContracts
    ): void {
        $this->cityName = $cityName;
        $this->cityLocative = $cityLocative;
        $this->consumption = $consumption;
        $this->localCompanyContracts = $localCompanyContracts;
        $this->regionalContracts = $regionalContracts;
    }

    /**
     * Check if there is any content to display.
     */
    public function getHasContentProperty(): bool
    {
        return $this->localCompanyContracts->isNotEmpty() || $this->regionalContracts->isNotEmpty();
    }

    public function render()
    {
        $localCompanyContracts = $this->prepareContractsForCards($this->localCompanyContracts);
        $regionalContracts = $this->prepareContractsForCards($this->regionalContracts);

        return view('livewire.local-contracts-section', [
            'localCompanyContracts' => $localCompanyContracts,
            'regionalContracts' => $regionalContracts,
            'hasContent' => $localCompanyContracts->isNotEmpty() || $regionalContracts->isNotEmpty(),
            'cityName' => $this->cityName,
            'cityLocative' => $this->cityLocative,
            'consumption' => $this->consumption,
        ]);
    }

    /**
     * Ensure card relations are available in bulk if this child component is
     * rendered with slim/rehydrated contract models. The card partials avoid
     * lazy relation access, so this preserves logos and energy badges without
     * one company/electricity_sources query per contract.
     */
    private function prepareContractsForCards(Collection $contracts): Collection
    {
        if ($contracts instanceof EloquentCollection) {
            $contracts->loadMissing(['company', 'electricitySource']);
        }

        return $contracts;
    }
}
