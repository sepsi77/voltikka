<?php

namespace App\Services\ContractPriceHistory;

use App\Enums\PricingModel;
use App\Models\ElectricityContract;
use App\Support\ContractContentSanitizer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContractHistoryPresenter
{
    /**
     * Display order for price component types in the history timeline and its
     * trend chart. Earlier entries are also preferred as the charted series.
     *
     * `price_component_type` is written verbatim from the upstream API payload
     * (`CanonicalPriceComponentWriter`), so this list can never be exhaustive by
     * construction. Types outside it are appended under their raw name rather
     * than dropped — the old hardcoded whitelist silently hid the `Spot` margin
     * component from the history of the Hybrid contract that carries it.
     */
    public const PRICE_TYPE_ORDER = [
        'General',
        'Spot',
        'DayTime',
        'NightTime',
        'SeasonalWinter',
        'SeasonalWinterDay',
        'SeasonalOther',
        'Monthly',
    ];

    /**
     * @return array{
     *     priceHistory: array<string, array<array{date: string, price: float, contract_id: string, contract_name: string}>>,
     *     contractHistory: array<int, array{
     *         id: string,
     *         name: string,
     *         company: string|null,
     *         is_current: bool,
     *         is_active: bool,
     *         latest_price_date: ?Carbon,
     *         last_seen_on_sale_date: ?Carbon,
     *         prices: array<int, array{type: string, label: string, price: float, unit: string}>,
     *         promotion: ?string
     *     }>,
     *     priceTypeLabels: array<string, string>,
     *     priceTypeOrder: array<int, string>
     * }
     */
    public function present(ElectricityContract $contract): array
    {
        $historyContracts = $this->getHistoryContracts($contract);
        $priceHistory = $this->priceHistory($historyContracts);

        return [
            'priceHistory' => $priceHistory,
            'contractHistory' => $this->contractHistory($contract, $historyContracts),
            'priceTypeLabels' => $this->priceTypeLabelsFor($contract),
            'priceTypeOrder' => $this->orderPriceTypes(array_keys($priceHistory)),
        ];
    }

    /**
     * @param  Collection<int, ElectricityContract>  $historyContracts
     * @return array<string, array<array{date: string, price: float, contract_id: string, contract_name: string}>>
     */
    protected function priceHistory(Collection $historyContracts): array
    {
        $history = [];

        foreach ($historyContracts as $historyContract) {
            foreach ($historyContract->priceComponents->sortByDesc('price_date')->groupBy('price_component_type') as $type => $components) {
                foreach ($components as $pc) {
                    $history[$type][] = [
                        'date' => $pc->price_date->format('Y-m-d'),
                        'price' => $pc->price,
                        'contract_id' => $historyContract->id,
                        'contract_name' => ContractContentSanitizer::displayName($historyContract->name),
                    ];
                }
            }
        }

        foreach ($history as $type => $rows) {
            $history[$type] = collect($rows)
                ->sortByDesc(fn (array $row) => $row['date'])
                ->values()
                ->toArray();
        }

        return $history;
    }

    /**
     * @param  Collection<int, ElectricityContract>  $historyContracts
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     company: string|null,
     *     is_current: bool,
     *     is_active: bool,
     *     latest_price_date: ?Carbon,
     *     last_seen_on_sale_date: ?Carbon,
     *     prices: array<int, array{type: string, label: string, price: float, unit: string}>,
     *     promotion: ?string
     * }>
     */
    protected function contractHistory(ElectricityContract $contract, Collection $historyContracts): array
    {
        return $historyContracts
            ->map(function (ElectricityContract $historyContract) use ($contract): array {
                $latestPriceComponents = $historyContract->priceComponents
                    ->sortByDesc('price_date')
                    ->groupBy('price_component_type')
                    ->map(fn ($group) => $group->sortByDesc('price_date')->first(fn ($item) => $item->price > 0) ?? $group->sortByDesc('price_date')->first());

                $latestPriceDate = $latestPriceComponents
                    ->pluck('price_date')
                    ->filter()
                    ->sortByDesc(fn ($date) => $date instanceof Carbon ? $date->timestamp : Carbon::parse($date)->timestamp)
                    ->first();

                // This is the last import date on which Voltikka observed this
                // exact contract. It is not an exact removal/expiry date.
                $lastSeenOnSaleDate = $historyContract->priceComponents
                    ->pluck('price_date')
                    ->filter()
                    ->sortByDesc(fn ($date) => $date instanceof Carbon ? $date->timestamp : Carbon::parse($date)->timestamp)
                    ->first();

                return [
                    'id' => $historyContract->id,
                    'name' => ContractContentSanitizer::displayName($historyContract->name),
                    'company' => $historyContract->company?->name,
                    'is_current' => $historyContract->id === $contract->id,
                    'is_active' => $historyContract->isActive(),
                    'latest_price_date' => $latestPriceDate,
                    'last_seen_on_sale_date' => $lastSeenOnSaleDate,
                    'prices' => $this->formatContractHistoryPrices($historyContract, $latestPriceComponents->all()),
                    'promotion' => $this->formatHistoricalPromotionText($historyContract, $latestPriceComponents->all()),
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * @return Collection<int, ElectricityContract>
     */
    protected function getHistoryContracts(ElectricityContract $contract): Collection
    {
        $historyContractIds = $this->getBackwardReplacementChainIds($contract->id)
            ->pluck('id')
            ->push($contract->id)
            ->unique()
            ->values();

        return ElectricityContract::query()
            ->with(['company', 'priceComponents', 'activeContract'])
            ->whereIn('id', $historyContractIds)
            ->get()
            ->sortByDesc(function (ElectricityContract $historyContract) {
                $latestPriceDate = $historyContract->priceComponents
                    ->pluck('price_date')
                    ->filter()
                    ->sortByDesc(fn ($date) => $date instanceof Carbon ? $date->timestamp : Carbon::parse($date)->timestamp)
                    ->first();

                return $latestPriceDate instanceof Carbon
                    ? $latestPriceDate->timestamp
                    : ($latestPriceDate ? Carbon::parse($latestPriceDate)->timestamp : 0);
            })
            ->values();
    }

    /**
     * Return predecessor contract IDs for the replacement history in one
     * recursive query instead of querying each replacement depth and then
     * re-querying all versions. Depth is capped defensively in case bad data
     * creates a cycle.
     *
     * @return Collection<int, object{id: string, depth: int}>
     */
    protected function getBackwardReplacementChainIds(string $contractId): Collection
    {
        return collect(DB::select(<<<'SQL'
            WITH RECURSIVE replacement_chain(id, replaced_by_contract_id, depth) AS (
                SELECT id, replaced_by_contract_id, 1
                FROM electricity_contracts
                WHERE replaced_by_contract_id = ?

                UNION ALL

                SELECT ec.id, ec.replaced_by_contract_id, replacement_chain.depth + 1
                FROM electricity_contracts ec
                INNER JOIN replacement_chain ON ec.replaced_by_contract_id = replacement_chain.id
                WHERE replacement_chain.depth < 25
            )
            SELECT id, depth FROM replacement_chain
        SQL, [$contractId]));
    }

    /**
     * A spot contract stores the supplier margin in its `General` component, not
     * the energy price the customer pays. A `Spot` component is a margin whatever
     * the contract's pricing model is. Both winter spellings are mapped because
     * upstream has used both.
     *
     * @return array<string, string>
     */
    protected function priceTypeLabelsFor(ElectricityContract $contract): array
    {
        return [
            'General' => $contract->pricingModelType() === PricingModel::Spot ? 'Marginaali' : 'Energiahinta',
            'Spot' => 'Marginaali',
            'Monthly' => 'Perusmaksu',
            'DayTime' => 'Päiväsähkö',
            'NightTime' => 'Yösähkö',
            'SeasonalWinter' => 'Talvihinta',
            'SeasonalWinterDay' => 'Talvihinta',
            'SeasonalOther' => 'Muu aika',
        ];
    }

    /**
     * @param  array<string, \App\Models\PriceComponent>  $latestPriceComponents
     * @return array<int, array{type: string, label: string, price: float, unit: string}>
     */
    protected function formatContractHistoryPrices(ElectricityContract $contract, array $latestPriceComponents): array
    {
        $priceTypeLabels = $this->priceTypeLabelsFor($contract);

        return collect($this->orderPriceTypes(array_keys($latestPriceComponents)))
            ->map(function (string $type) use ($latestPriceComponents, $priceTypeLabels) {
                $component = $latestPriceComponents[$type] ?? null;

                if (! $component) {
                    return null;
                }

                return [
                    'type' => $type,
                    'label' => $priceTypeLabels[$type] ?? $type,
                    'price' => (float) $component->price,
                    'unit' => $type === 'Monthly' ? 'EUR/kk' : 'c/kWh',
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Known types in display order, then any unrecognized upstream type.
     *
     * @param  array<int, string>  $types
     * @return array<int, string>
     */
    protected function orderPriceTypes(array $types): array
    {
        return array_values(array_merge(
            array_intersect(self::PRICE_TYPE_ORDER, $types),
            array_diff($types, self::PRICE_TYPE_ORDER),
        ));
    }

    /**
     * @param  array<string, \App\Models\PriceComponent>  $latestPriceComponents
     */
    protected function formatHistoricalPromotionText(ElectricityContract $contract, array $latestPriceComponents): ?string
    {
        $discountedComponent = collect($latestPriceComponents)
            ->filter(fn ($component) => $component?->has_discount)
            ->sortByDesc('price_date')
            ->first();

        if (! $discountedComponent) {
            return $contract->pricing_has_discounts ? 'Tarjoussopimus' : null;
        }

        $parts = [];

        if ($discountedComponent->discount_discount_n_first_months) {
            $parts[] = $discountedComponent->discount_discount_n_first_months.' ensimmäistä kuukautta';
        }

        if ($discountedComponent->discount_value) {
            $parts[] = $contract->formatActiveDiscountValue([
                'value' => $discountedComponent->discount_value,
                'is_percentage' => $discountedComponent->discount_is_percentage,
                'price_component_type' => $discountedComponent->price_component_type,
                'payment_unit' => $discountedComponent->payment_unit,
            ]);
        }

        if ($discountedComponent->discount_discount_until_date) {
            $parts[] = 'voimassa '.$discountedComponent->discount_discount_until_date->format('d.m.Y').' asti';
        }

        if (empty($parts)) {
            return 'Tarjoussopimus';
        }

        return implode(' · ', $parts);
    }
}
