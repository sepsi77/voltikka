<?php

namespace App\Services\CanonicalPricing\ForwardPremium;

use Carbon\CarbonImmutable;

final readonly class PremiumEstimate
{
    public const PUBLIC_KEYS = [
        'source', 'premiums_by_bucket', 'lineage_count', 'company_count', 'observation_count',
        'independent_variant_count', 'confidence', 'reference_trade_dates', 'evidence_from',
        'evidence_through', 'flags', 'references',
    ];

    public const PUBLIC_REFERENCE_KEYS = ['pricing_date', 'reference_period_proxy', 'delivery_start', 'delivery_end', 'provenance'];

    public const PUBLIC_FLAGS = [
        'conflicting_latest_lineage_evidence',
        'equivalent_energy_offers_deduplicated',
        'conflicting_latest_variant_evidence',
        'reset_reference_period_proxy',
        'observed_pricing_date_reference_period_proxy',
        'single_independent_energy_variant',
        'single_source_company',
        'transferred_comparable_premium',
    ];

    /** @var list<string> */
    public array $sourceLineages;

    /** @var list<string> */
    public array $sourceCompanies;

    /** @var list<string> ISO calendar dates. */
    public array $referenceTradeDates;

    public int $lineageCount;

    public int $companyCount;

    public int $observationCount;

    public CarbonImmutable $evidenceFrom;

    public CarbonImmutable $evidenceThrough;

    public bool $sparse;

    public bool $lowerConfidence;

    /**
     * observations retain delivery bounds, signature, and reason provenance for each source.
     * Counts describe evidence, not forecast accuracy. Variants are the independent weights.
     *
     * @param  array<string, float>  $premiumsByBucket
     * @param  non-empty-list<PremiumObservation>  $observations
     * @param  list<string>  $flags
     */
    public function __construct(
        public PremiumSource $source,
        public array $premiumsByBucket,
        public array $observations,
        public int $independentVariantCount,
        public array $flags,
    ) {
        $lineages = $companies = $references = $dates = [];
        foreach ($observations as $observation) {
            $lineages[] = $observation->lineageId;
            $companies[] = $observation->companyName;
            $references[] = $observation->referenceTradeDate->toDateString();
            $dates[] = $observation->observedAt;
        }
        $this->sourceLineages = self::uniqueSorted($lineages);
        $this->sourceCompanies = self::uniqueSorted($companies);
        $this->referenceTradeDates = self::uniqueSorted($references);
        $this->lineageCount = count($this->sourceLineages);
        $this->companyCount = count($this->sourceCompanies);
        $this->observationCount = count($observations);
        $this->evidenceFrom = min($dates);
        $this->evidenceThrough = max($dates);
        $this->sparse = $independentVariantCount === 1 || $this->companyCount === 1;
        $this->lowerConfidence = $source !== PremiumSource::OwnLineage || $this->sparse || $flags !== [];
    }

    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'premiums_by_bucket' => $this->premiumsByBucket,
            'lineage_count' => $this->lineageCount,
            'company_count' => $this->companyCount,
            'observation_count' => $this->observationCount,
            'independent_variant_count' => $this->independentVariantCount,
            'confidence' => $this->lowerConfidence ? 'lower' : 'higher',
            'reference_trade_dates' => $this->referenceTradeDates,
            'evidence_from' => $this->evidenceFrom->toDateString(),
            'evidence_through' => $this->evidenceThrough->toDateString(),
            'flags' => array_values(array_intersect($this->flags, self::PUBLIC_FLAGS)),
            'references' => array_map(fn (PremiumObservation $row) => [
                'pricing_date' => $row->pricingDate?->toDateString(),
                'reference_period_proxy' => $row->referencePeriodProxy,
                'delivery_start' => $row->referenceDeliveryStart->toDateString(),
                'delivery_end' => $row->referenceDeliveryEnd->toDateString(),
                // Public model facts only; source identities and free text stay in observations.
                'provenance' => self::publicProvenance($row->compatibility->family, $row->referencePeriodProxy, $row->compatibility->vatBasis),
            ], $this->observations),
        ];
    }

    public static function publicProvenance(PremiumFamily $family, bool $proxy, PremiumVatBasis $vat): string
    {
        return $family->value.($proxy ? ';reference_period_proxy' : ';matched_reference_period')
            .';target_audience_vat_normalized_unknown_assumed;vat_basis='.$vat->value;
    }

    /** @return list<string> */
    private static function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}
