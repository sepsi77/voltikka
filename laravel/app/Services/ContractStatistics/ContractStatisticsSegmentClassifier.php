<?php

namespace App\Services\ContractStatistics;

use App\Enums\ContractType;
use App\Enums\PricingModel;
use App\Models\ElectricityContract;
use App\Services\ContractCard\Enums\PricingBucket;
use App\Services\ContractCard\PricingCategoryResolver;
use App\Services\ContractListing\ContractListingPipeline;

class ContractStatisticsSegmentClassifier
{
    /** Consumer-facing statistics segment labels, in display order. */
    public const SEGMENT_LABELS = [
        'spot' => 'Pörssisähkö',
        'market_reset' => 'Päivittyvä hinta',
        'hybrid' => 'Joustosähkö',
        'quarterly' => 'Kvartaalisähkö',
        'fixed_term_below6' => 'Määräaikainen alle 6 kk',
        'fixed_term_6' => 'Määräaikainen 6 kk',
        'fixed_term_7_11' => 'Määräaikainen 7–11 kk',
        'fixed_term_12' => 'Määräaikainen 12 kk',
        'fixed_term_13_23' => 'Määräaikainen 13–23 kk',
        'fixed_term_24' => 'Määräaikainen 24 kk',
        'fixed_term_over24' => 'Määräaikainen yli 24 kk',
        'fixed_term_other' => 'Määräaikainen muu',
        'open_ended' => 'Toistaiseksi voimassa oleva',
    ];

    public function __construct(private readonly PricingCategoryResolver $pricingCategoryResolver) {}

    public function classify(ElectricityContract $contract, ContractPriceBasis $basis): string
    {
        if ($basis === ContractPriceBasis::CanonicalCalculation) {
            return match (PricingBucket::fromFacts($this->pricingCategoryResolver->resolve($contract))) {
                PricingBucket::Spot => 'spot',
                PricingBucket::MarketReset => 'market_reset',
                PricingBucket::ConsumptionEffect => 'hybrid',
                PricingBucket::Fixed => $this->structuralSegment($contract),
            };
        }

        return $this->observedSegment($contract);
    }

    private function observedSegment(ElectricityContract $contract): string
    {
        if ($contract->pricingModelType() === PricingModel::Spot) {
            return 'spot';
        }

        if ($contract->pricingModelType() === PricingModel::Hybrid) {
            return 'hybrid';
        }

        if (ContractListingPipeline::matchesQuarterly(
            $contract->name,
            $contract->extra_information_fi,
            $contract->short_description,
            $contract->long_description,
        )) {
            return 'quarterly';
        }

        return $this->structuralSegment($contract);
    }

    private function structuralSegment(ElectricityContract $contract): string
    {
        if ($contract->contractTypeValue() === ContractType::FixedTerm) {
            return 'fixed_term_'.match ($contract->fixed_time_range) {
                'Below6' => 'below6',
                'Fixed6' => '6',
                'Between711' => '7_11',
                'Fixed12' => '12',
                'Between1323' => '13_23',
                'Fixed24' => '24',
                'Over24' => 'over24',
                default => 'other',
            };
        }

        if ($contract->contractTypeValue() === ContractType::OpenEnded) {
            return 'open_ended';
        }

        return 'other';
    }
}
