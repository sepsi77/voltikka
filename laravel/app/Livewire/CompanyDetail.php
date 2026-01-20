<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\ElectricityContract;
use Illuminate\Support\Collection;
use Livewire\Component;

class CompanyDetail extends Component
{
    public ?Company $company = null;
    public string $companySlug;

    public function mount(string $companySlug): void
    {
        $this->companySlug = $companySlug;
        $this->company = Company::where('name_slug', $companySlug)->first();
    }

    /**
     * Get all contracts for this company.
     */
    public function getContractsProperty(): Collection
    {
        if (!$this->company) {
            return collect();
        }

        return ElectricityContract::query()
            ->with(['priceComponents', 'electricitySource'])
            ->where('company_name', $this->company->name)
            ->get();
    }

    public function render()
    {
        if (!$this->company) {
            abort(404, 'Yritystä ei löytynyt');
        }

        return view('livewire.company-detail', [
            'contracts' => $this->contracts,
        ])->layout('layouts.app', ['title' => "{$this->company->name} - Sähkösopimukset"]);
    }
}
