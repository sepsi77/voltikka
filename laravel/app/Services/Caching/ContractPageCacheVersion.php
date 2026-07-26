<?php

namespace App\Services\Caching;

use App\Models\ContractPercentile;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\PriceComponent;
use App\Models\SpotPriceAverage;
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
     */
    private const PAYLOAD_SCHEMA_VERSION = 3;

    public function __construct(
        private readonly ContractListCacheService $contractListCache,
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
            'payload_schema_version' => self::PAYLOAD_SCHEMA_VERSION,
            'contract_list_cache_version' => $this->contractListCache->getVersion(),
            // Toggling canonical pricing changes rendered totals, exclusions, and labels.
            'canonical_pricing_enabled' => (bool) config('canonical_pricing.enabled', false),
            // Toggling the market-reset forward shift changes reset totals and their ordering.
            'reset_forward_shift_enabled' => (bool) config('canonical_pricing.reset_forward_shift.enabled', false),
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
