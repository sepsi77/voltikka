<?php

namespace App\Models;

use App\Services\CO2EmissionsCalculator;
use App\Services\DTO\CO2EmissionsResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectricitySource extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'electricity_sources';

    /**
     * The primary key associated with the table.
     * This is a one-to-one relationship with ElectricityContract.
     */
    protected $primaryKey = 'contract_id';

    /**
     * Indicates if the model's ID is auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The data type of the primary key.
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model should be timestamped.
     * The legacy table doesn't have timestamps.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'contract_id',
        'renewable_total',
        'renewable_biomass',
        'renewable_solar',
        'renewable_wind',
        'renewable_general',
        'renewable_hydro',
        'fossil_total',
        'fossil_oil',
        'fossil_coal',
        'fossil_natural_gas',
        'fossil_peat',
        'nuclear_total',
        'nuclear_general',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'renewable_total' => 'float',
            'renewable_biomass' => 'float',
            'renewable_solar' => 'float',
            'renewable_wind' => 'float',
            'renewable_general' => 'float',
            'renewable_hydro' => 'float',
            'fossil_total' => 'float',
            'fossil_oil' => 'float',
            'fossil_coal' => 'float',
            'fossil_natural_gas' => 'float',
            'fossil_peat' => 'float',
            'nuclear_total' => 'float',
            'nuclear_general' => 'float',
        ];
    }

    /**
     * Get the electricity contract that this source belongs to.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ElectricityContract::class, 'contract_id', 'id');
    }

    /**
     * Check if the energy source is 100% renewable.
     */
    public function isFullyRenewable(): bool
    {
        return $this->renewable_total >= 100.0;
    }

    /**
     * Check if the energy source has no fossil fuels.
     */
    public function isFossilFree(): bool
    {
        return $this->fossil_total === null || $this->fossil_total <= 0.0;
    }

    /**
     * Check if the energy source includes nuclear power.
     */
    public function hasNuclear(): bool
    {
        return $this->nuclear_total !== null && $this->nuclear_total > 0.0;
    }

    /**
     * Calculate CO2 emissions for a given annual consumption.
     */
    public function calculateEmissions(float $annualConsumptionKwh): CO2EmissionsResult
    {
        $calculator = app(CO2EmissionsCalculator::class);

        return $calculator->calculate($this, $annualConsumptionKwh);
    }

    /**
     * Get the emission factor (gCO2/kWh) for this energy source mix.
     */
    public function getEmissionFactor(): float
    {
        $calculator = app(CO2EmissionsCalculator::class);

        return $calculator->calculateEmissionFactor($this);
    }

    /**
     * Get the total percentage of reported energy sources.
     * If less than 100%, the remainder uses residual mix.
     */
    public function getReportedSourcesTotal(): float
    {
        $total = ($this->renewable_total ?? 0)
            + ($this->fossil_total ?? 0)
            + ($this->nuclear_total ?? 0);

        return min(100, $total);
    }

    /**
     * Check if energy sources are fully reported (add up to ~100%).
     */
    public function hasCompleteSourceData(): bool
    {
        return $this->getReportedSourcesTotal() >= 99.5;
    }
}
