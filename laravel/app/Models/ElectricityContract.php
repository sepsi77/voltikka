<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ElectricityContract extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'electricity_contracts';

    /**
     * The primary key associated with the table.
     */
    protected $primaryKey = 'id';

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
        'id',
        'api_id',
        'replaced_by_contract_id',
        'current_source_observation_id',
        'published_interpretation_id',
        'canonical_pricing',
        'canonical_source_consistency',
        'canonical_calculation',
        'company_name',
        'name',
        'name_slug',
        'contract_type',
        'spot_price_selection',
        'fixed_time_range',
        'metering',
        'pricing_model',
        'target_group',
        'short_description',
        'long_description',
        'pricing_name',
        'pricing_has_discounts',
        'consumption_control',
        'consumption_limitation_min_x_kwh_per_y',
        'consumption_limitation_max_x_kwh_per_y',
        'pre_billing',
        'available_for_existing_users',
        'delivery_responsibility_product',
        'order_link',
        'product_link',
        'billing_frequency',
        'time_period_definitions',
        'transparency_index',
        'extra_information_default',
        'extra_information_fi',
        'extra_information_en',
        'extra_information_sv',
        'availability_is_national',
        'microproduction_buys',
        'microproduction_default',
        'microproduction_fi',
        'microproduction_sv',
        'microproduction_en',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'canonical_pricing' => 'array',
            'canonical_source_consistency' => 'array',
            'canonical_calculation' => 'array',
            'pricing_has_discounts' => 'boolean',
            'consumption_control' => 'boolean',
            'consumption_limitation_min_x_kwh_per_y' => 'float',
            'consumption_limitation_max_x_kwh_per_y' => 'float',
            'pre_billing' => 'boolean',
            'available_for_existing_users' => 'boolean',
            'delivery_responsibility_product' => 'boolean',
            'billing_frequency' => 'array',
            'time_period_definitions' => 'array',
            'transparency_index' => 'array',
            'availability_is_national' => 'boolean',
            'microproduction_buys' => 'boolean',
        ];
    }

    /**
     * Boot the model and register event listeners.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (ElectricityContract $contract) {
            // Auto-generate name_slug
            $contract->name_slug = static::generateSlug($contract->name);

            // Auto-generate api_id if not provided (for tests and legacy compatibility)
            if (empty($contract->api_id)) {
                $contract->api_id = (string) Str::uuid();
            }
        });
    }

    /**
     * Generate a Finnish-compatible slug from a name.
     * Replaces Finnish characters (ä, ö, å) with ASCII equivalents.
     * Uses the same logic as Company::generateSlug for consistency.
     */
    public static function generateSlug(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = str_replace(['ä', 'ö', 'å'], ['a', 'o', 'a'], $slug);
        // Remove any character that isn't alphanumeric, whitespace, or hyphen
        $slug = preg_replace('/[^\w\s-]/u', '', $slug);
        // Replace underscores and whitespace with hyphens
        $slug = preg_replace('/[\s_]+/', '-', $slug);
        // Remove consecutive hyphens
        $slug = preg_replace('/-+/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Generate a custom contract ID.
     * Format: {random}-{company-slug}-{contract-slug}
     * Example: x7k9m2-fortum-tuntisahko
     */
    public static function generateId(string $companyName, string $contractName): string
    {
        $random = strtolower(Str::random(6));
        $companySlug = static::generateSlug($companyName);
        $contractSlug = static::generateSlug($contractName);

        return "{$random}-{$companySlug}-{$contractSlug}";
    }

    /**
     * Get the company that owns the contract.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_name', 'name');
    }

    /**
     * Get the price components for the contract.
     */
    public function priceComponents(): HasMany
    {
        return $this->hasMany(PriceComponent::class, 'electricity_contract_id', 'id');
    }

    /**
     * Get the versioned upstream source payloads for the contract.
     */
    public function sourceSnapshots(): HasMany
    {
        return $this->hasMany(ContractSourceSnapshot::class, 'contract_id', 'id');
    }

    /**
     * Get the chronological source-observation episodes for the contract.
     */
    public function sourceObservations(): HasMany
    {
        return $this->hasMany(ContractSourceObservation::class, 'contract_id', 'id');
    }

    /**
     * Get the one source observation that defines currentness.
     */
    public function currentSourceObservation(): BelongsTo
    {
        return $this->belongsTo(ContractSourceObservation::class, 'current_source_observation_id');
    }

    /**
     * Get all automated interpretations for the contract.
     */
    public function interpretations(): HasMany
    {
        return $this->hasMany(ContractInterpretation::class, 'contract_id', 'id');
    }

    /**
     * Get the interpretation currently published to the canonical fields.
     */
    public function publishedInterpretation(): BelongsTo
    {
        return $this->belongsTo(ContractInterpretation::class, 'published_interpretation_id');
    }

    /**
     * Get the electricity source for the contract.
     */
    public function electricitySource(): HasOne
    {
        return $this->hasOne(ElectricitySource::class, 'contract_id', 'id');
    }

    /**
     * Get the postcodes where this contract is available.
     */
    public function availabilityPostcodes(): BelongsToMany
    {
        return $this->belongsToMany(
            Postcode::class,
            'contract_postcode',
            'contract_id',
            'postcode',
            'id',
            'postcode'
        );
    }

    /**
     * Get the DSOs (Distribution System Operators) where this contract is available.
     */
    public function dsos(): BelongsToMany
    {
        return $this->belongsToMany(
            Dso::class,
            'contract_dso',
            'contract_id',
            'dso_id',
            'id',
            'id'
        );
    }

    /**
     * Check if this contract is regional (not available nationally).
     */
    public function isRegional(): bool
    {
        return $this->availability_is_national === false;
    }

    /**
     * Get the active contract record for this contract.
     */
    public function activeContract(): HasOne
    {
        return $this->hasOne(ActiveContract::class, 'id', 'id');
    }

    /**
     * The contract that replaced this one.
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_contract_id', 'id');
    }

    /**
     * Historical contracts that directly point to this contract as their replacement.
     */
    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'replaced_by_contract_id', 'id');
    }

    /**
     * Scope a query to only include active contracts.
     *
     * Active contracts are those that have a corresponding record in the active_contracts table.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereHas('activeContract');
    }

    /**
     * Check if this contract is active (present in the active_contracts table).
     */
    public function isActive(): bool
    {
        if ($this->relationLoaded('activeContract')) {
            return $this->activeContract !== null;
        }

        return $this->activeContract()->exists();
    }

    /**
     * Get the forward replacement chain starting from this contract.
     *
     * @return Collection<int, self>
     */
    public function getReplacementChainForward(): Collection
    {
        $chain = collect();
        $seen = [];
        $current = $this->replacedBy;

        while ($current && ! isset($seen[$current->id])) {
            $chain->push($current);
            $seen[$current->id] = true;
            $current = $current->replacedBy;
        }

        return $chain;
    }

    /**
     * Get all predecessor contracts that eventually lead to this contract.
     *
     * @return Collection<int, self>
     */
    public function getReplacementChainBackward(): Collection
    {
        $results = collect();
        $frontier = collect([$this->id]);
        $seen = [];

        while ($frontier->isNotEmpty()) {
            $predecessors = self::query()
                ->whereIn('replaced_by_contract_id', $frontier->all())
                ->get();

            $frontier = collect();

            foreach ($predecessors as $current) {
                if (isset($seen[$current->id])) {
                    continue;
                }

                $seen[$current->id] = true;
                $results->push($current);
                $frontier->push($current->id);
            }
        }

        return $results;
    }

    /**
     * Get this contract and every predecessor in its replacement lineage.
     *
     * The replacement graph can converge, so this returns a set and not a path.
     * Plain UNION also makes malformed cycles terminate without a depth limit.
     *
     * @return Collection<int, string>
     */
    public function getReplacementLineageIds(): Collection
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE replacement_lineage(id) AS (
                SELECT id
                FROM electricity_contracts
                WHERE id = ?

                UNION

                SELECT contracts.id
                FROM electricity_contracts AS contracts
                INNER JOIN replacement_lineage AS lineage
                    ON contracts.replaced_by_contract_id = lineage.id
            )
            SELECT id
            FROM replacement_lineage
            SQL, [$this->id]);

        return collect($rows)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values();
    }

    /**
     * Get all raw price components in this replacement lineage, newest first.
     *
     * @return Collection<int, PriceComponent>
     */
    public function getLineagePriceComponents(): Collection
    {
        return PriceComponent::query()
            ->whereIn('electricity_contract_id', $this->getReplacementLineageIds())
            ->orderByDesc('price_date')
            ->orderBy('electricity_contract_id')
            ->orderBy('price_component_type')
            ->orderBy('id')
            ->get();
    }

    /**
     * Resolve the latest reachable replacement in the forward chain.
     */
    public function resolveLatestReplacement(): ?self
    {
        $seen = [$this->id => true];
        $current = $this;
        $latest = null;

        while ($current->replacedBy && ! isset($seen[$current->replacedBy->id])) {
            $current = $current->replacedBy;
            $seen[$current->id] = true;
            $latest = $current;
        }

        return $latest;
    }

    /**
     * Check if a given consumption value falls within the contract's allowed range.
     *
     * If either limit is null, treat it as no restriction on that side.
     *
     * @param  int|float  $consumption  The consumption value in kWh/year to check
     * @return bool True if the consumption is within the allowed range
     */
    public function isConsumptionInRange(int|float $consumption): bool
    {
        $min = $this->consumption_limitation_min_x_kwh_per_y;
        $max = $this->consumption_limitation_max_x_kwh_per_y;

        if ($min !== null && $consumption < $min) {
            return false;
        }

        if ($max !== null && $consumption > $max) {
            return false;
        }

        return true;
    }

    /**
     * Check if this contract has any consumption limitations.
     *
     * @return bool True if either min or max consumption limit is set
     */
    public function hasConsumptionLimits(): bool
    {
        return $this->consumption_limitation_min_x_kwh_per_y !== null
            || $this->consumption_limitation_max_x_kwh_per_y !== null;
    }

    /**
     * Get latest price components normalized for pricing calculations.
     *
     * Picks the newest component per type, preferring non-zero prices when duplicates exist.
     * Includes discount metadata so pricing calculations can account for promotions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLatestPriceComponentsForCalculation(): array
    {
        $priceComponents = $this->relationLoaded('priceComponents')
            ? $this->priceComponents
            : $this->priceComponents()
                ->orderByDesc('price_date')
                ->get();

        return self::normalizePriceComponentsForCalculation($priceComponents);
    }

    /**
     * Get latest normalized price components for many contracts in one query.
     *
     * This avoids per-contract `price_components` queries on listing pages while
     * still loading only the component rows needed for calculations, not the full
     * historical component relationship for every active contract.
     *
     * @param  iterable<string>  $contractIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function getLatestPriceComponentsForCalculationByContractIds(iterable $contractIds): array
    {
        $ids = collect($contractIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $ranked = PriceComponent::query()
            ->select([
                'electricity_contract_id',
                'price_component_type',
                'payment_unit',
                'price',
                'has_discount',
                'discount_value',
                'discount_is_percentage',
                'discount_type',
                'discount_discount_n_first_kwh',
                'discount_discount_n_first_months',
                'discount_discount_until_date',
                'price_date',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY electricity_contract_id, price_component_type ORDER BY CASE WHEN price > 0 THEN 1 ELSE 0 END DESC, price_date DESC) as component_rank')
            ->whereIn('electricity_contract_id', $ids);

        $rows = \Illuminate\Support\Facades\DB::query()
            ->fromSub($ranked, 'ranked_price_components')
            ->where('component_rank', 1)
            ->get();

        return $rows
            ->groupBy('electricity_contract_id')
            ->map(fn ($components) => $components
                ->map(fn ($pc) => [
                    'price_component_type' => $pc->price_component_type,
                    'payment_unit' => $pc->payment_unit,
                    'price' => $pc->price,
                    'has_discount' => (bool) $pc->has_discount,
                    'discount_value' => $pc->discount_value,
                    'discount_is_percentage' => $pc->discount_is_percentage === null ? null : (bool) $pc->discount_is_percentage,
                    'discount_type' => $pc->discount_type,
                    'discount_discount_n_first_kwh' => $pc->discount_discount_n_first_kwh,
                    'discount_discount_n_first_months' => $pc->discount_discount_n_first_months,
                    'discount_discount_until_date' => $pc->discount_discount_until_date,
                ])
                ->values()
                ->toArray())
            ->toArray();
    }

    /**
     * Get price components for one exact historical calculation date.
     *
     * Statistics backfills use this instead of latest components so each daily
     * snapshot reflects the prices imported for that day only.
     *
     * @param  \DateTimeInterface|string  $date
     * @return array<int, array<string, mixed>>
     */
    public function getPriceComponentsForCalculationDate($date): array
    {
        $priceComponents = $this->priceComponents()
            ->whereDate('price_date', $date)
            ->get();

        return self::normalizePriceComponentsForCalculation($priceComponents);
    }

    /**
     * Normalize a price-component collection for ContractPriceCalculator.
     *
     * @param  \Illuminate\Support\Collection<int, PriceComponent>  $priceComponents
     * @return array<int, array<string, mixed>>
     */
    private static function normalizePriceComponentsForCalculation(Collection $priceComponents): array
    {
        return $priceComponents
            ->sortByDesc('price_date')
            ->groupBy('price_component_type')
            ->map(fn ($group) => $group->sortByDesc('price_date')->first(fn ($item) => $item->price > 0) ?? $group->sortByDesc('price_date')->first())
            ->values()
            ->map(fn ($pc) => [
                'price_component_type' => $pc->price_component_type,
                'payment_unit' => $pc->payment_unit,
                'price' => $pc->price,
                'has_discount' => $pc->has_discount,
                'discount_value' => $pc->discount_value,
                'discount_is_percentage' => $pc->discount_is_percentage,
                'discount_type' => $pc->discount_type,
                'discount_discount_n_first_kwh' => $pc->discount_discount_n_first_kwh,
                'discount_discount_n_first_months' => $pc->discount_discount_n_first_months,
                'discount_discount_until_date' => $pc->discount_discount_until_date,
            ])
            ->toArray();
    }

    /**
     * Check if the contract has any active discounts.
     *
     * A discount is considered active if:
     * - The contract's pricing_has_discounts flag is true, OR
     * - Any price component has has_discount=true AND the discount hasn't expired
     *   (discount_discount_until_date is NULL or in the future)
     *
     * @return bool True if the contract has active discounts
     */
    public function hasActiveDiscounts(): bool
    {
        if ($this->pricing_has_discounts) {
            return true;
        }

        $now = Carbon::now();

        if ($this->relationLoaded('priceComponents')) {
            return $this->priceComponents->contains(fn (PriceComponent $component) => $component->has_discount
                && ($component->discount_discount_until_date === null || $component->discount_discount_until_date->gt($now))
            );
        }

        return $this->priceComponents()
            ->where('has_discount', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('discount_discount_until_date')
                    ->orWhere('discount_discount_until_date', '>', $now);
            })
            ->exists();
    }

    /**
     * Get information about the first active discount found on this contract.
     *
     * Returns an array with discount details or null if no active discount exists.
     *
     * @return array{value: float|null, is_percentage: bool|null, n_first_months: int|null, until_date: \Carbon\Carbon|null, price_component_type: string|null, payment_unit: string|null}|null
     */
    public function getActiveDiscountInfo(): ?array
    {
        $now = Carbon::now();

        if ($this->relationLoaded('priceComponents')) {
            $priceComponent = $this->priceComponents->first(fn (PriceComponent $component) => $component->has_discount
                && ($component->discount_discount_until_date === null || $component->discount_discount_until_date->gt($now))
            );
        } else {
            $priceComponent = $this->priceComponents()
                ->where('has_discount', true)
                ->where(function ($query) use ($now) {
                    $query->whereNull('discount_discount_until_date')
                        ->orWhere('discount_discount_until_date', '>', $now);
                })
                ->first();
        }

        if (! $priceComponent) {
            return null;
        }

        return [
            'value' => $priceComponent->discount_value,
            'is_percentage' => $priceComponent->discount_is_percentage,
            'n_first_months' => $priceComponent->discount_discount_n_first_months,
            'until_date' => $priceComponent->discount_discount_until_date,
            'price_component_type' => $priceComponent->price_component_type,
            'payment_unit' => $priceComponent->payment_unit,
        ];
    }

    /**
     * Format the active discount amount using the discounted component's unit.
     */
    public function formatActiveDiscountValue(?array $discountInfo = null): ?string
    {
        $discountInfo ??= $this->getActiveDiscountInfo();

        if (! $discountInfo || ! $discountInfo['value']) {
            return null;
        }

        if ($discountInfo['is_percentage']) {
            return '-'.number_format($discountInfo['value'], 0, ',', ' ').'% alennus';
        }

        // The component TYPE wins over the stored unit, and that order is the
        // whole point. 563 stored `Monthly` rows carry
        // `payment_unit = CentPerKiwattHour` straight from the upstream payload,
        // across 25 contracts. Trusting the unit printed "-5,90 c/kWh alennus"
        // for a base-fee waiver on two live contracts, which reads as a discount
        // of about 295 EUR/yr at 5 000 kWh instead of the real 71 EUR/yr. A
        // monthly fee is never a per-kWh price, whatever the source says.
        // `ContractPriceCalculator` already applies the same precedence when it
        // costs the discount (`$paymentUnit === 'EurPerMonth' || $componentType
        // === 'Monthly'`), so the label now agrees with the arithmetic.
        $unit = match (true) {
            ($discountInfo['price_component_type'] ?? null) === 'Monthly' => '€/kk',
            ($discountInfo['payment_unit'] ?? null) === 'EurPerMonth' => '€/kk',
            ($discountInfo['payment_unit'] ?? null) === 'CentPerKiwattHour' => 'c/kWh',
            default => null,
        };

        if (! $unit) {
            return '-'.number_format($discountInfo['value'], 2, ',', ' ').' alennus';
        }

        return '-'.number_format($discountInfo['value'], 2, ',', ' ').' '.$unit.' alennus';
    }
}
