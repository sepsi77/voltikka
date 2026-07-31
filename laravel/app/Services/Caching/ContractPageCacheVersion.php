<?php

namespace App\Services\Caching;

use App\Models\ContractPercentile;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\PriceComponent;
use App\Models\SpotPriceAverage;
use App\Services\CalculatedCostPayloadSchema;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\ContractListCacheService;
use Illuminate\Support\Facades\DB;

class ContractPageCacheVersion
{
    /**
     * Shape marker for the prepared page payload.
     *
     * Every other field here tracks source DATA. None of them move when a deploy changes
     * what the cached payload contains, so a card that starts reading a new field would
     * render from a stale shape until the next import. Bump this whenever the prepared
     * contract payload gains or loses a field.
     *
     * v2: contract cards render from `ContractCardPresenter`, which reads the new
     * `pricing_integrity.promo_rate_cents` / `normal_rate_cents` fields.
     * v3: `calculated_cost.phase_breakdown` carries resolved window dates and per-phase
     * rates, and the contract detail page renders the presenter's view model.
     * v4: canonical offer outcomes carry measured promotion-free monthly costs and savings.
     * v5: short annualized terms carry their actual unannualized contract-term costs and saving.
     * v6: canonical package outcomes carry typed monthly allowance and excess-rate data.
     * v7: card/detail current values and offer copy are canonical-only in canonical mode.
     * v8: company and SEO offer surfaces use canonical measured membership and benefit copy.
     * v9: canonical outcomes carry exact typed offer terms for controlled public promotion copy.
     * v10: short BaseOnlyHybrid outcomes preserve real-term totals and offer savings.
     * v11: `other` cadence recurring resets become eligible canonical list estimates.
     */
    private const PREPARED_VIEW_SCHEMA_VERSION = 11;

    public function __construct(
        private readonly ContractListCacheService $contractListCache,
        private readonly PricingMode $pricingMode,
    ) {}

    /**
     * Cheap version fingerprint for contract listing/detail pages.
     *
     * The import pipeline bumps ContractListCacheService's version whenever the
     * active contract dataset changes. The aggregate fields below protect pages
     * from manual/test data changes and from source tables that affect displayed
     * list/detail costs or badges.
     *
     * @return array<string, mixed>
     */
    public function version(): array
    {
        $priceComponents = PriceComponent::query()
            ->selectRaw('COUNT(*) as row_count, MAX(price_date) as latest_price_date')
            ->first();

        $spotAverages = SpotPriceAverage::query()
            ->where('region', 'FI')
            ->selectRaw('COUNT(*) as row_count, MAX(period_start) as latest_period_start, MAX(period_end) as latest_period_end')
            ->first();

        return [
            'payload_schema_version' => self::PREPARED_VIEW_SCHEMA_VERSION,
            'calculated_cost_schema' => CalculatedCostPayloadSchema::cacheMarker(),
            'contract_list_cache_version' => $this->contractListCache->getVersion(),
            'pricing_mode' => $this->pricingMode->cacheMarker(),
            'active_contract_count' => DB::table('active_contracts')->count(),
            'contract_count' => ElectricityContract::query()->count(),
            'latest_contract_id' => ElectricityContract::query()->max('id'),
            'price_component_count' => (int) ($priceComponents?->row_count ?? 0),
            'latest_price_date' => $priceComponents?->latest_price_date,
            'electricity_source_count' => ElectricitySource::query()->count(),
            'contract_percentile_count' => ContractPercentile::query()->count(),
            'spot_average_count' => (int) ($spotAverages?->row_count ?? 0),
            'spot_average_latest_start' => $spotAverages?->latest_period_start,
            'spot_average_latest_end' => $spotAverages?->latest_period_end,
        ];
    }

    public function hash(): string
    {
        return md5(json_encode($this->version()));
    }
}
