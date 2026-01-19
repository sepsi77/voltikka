<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ElectricitySourceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'renewable_total' => $this->renewable_total,
            'renewable_biomass' => $this->renewable_biomass,
            'renewable_solar' => $this->renewable_solar,
            'renewable_wind' => $this->renewable_wind,
            'renewable_general' => $this->renewable_general,
            'renewable_hydro' => $this->renewable_hydro,
            'fossil_total' => $this->fossil_total,
            'fossil_oil' => $this->fossil_oil,
            'fossil_coal' => $this->fossil_coal,
            'fossil_natural_gas' => $this->fossil_natural_gas,
            'fossil_peat' => $this->fossil_peat,
            'nuclear_total' => $this->nuclear_total,
            'nuclear_general' => $this->nuclear_general,
        ];
    }
}
