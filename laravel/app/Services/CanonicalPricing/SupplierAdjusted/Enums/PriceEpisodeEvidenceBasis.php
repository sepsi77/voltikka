<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted\Enums;

enum PriceEpisodeEvidenceBasis: string
{
    case ObservedSellerSnapshotRun = 'observed_seller_snapshot_run';
    case CanonicalSnapshotRun = 'canonical_snapshot_run';
    case CurrentSourceObservation = 'current_source_observation';
    case CanonicalSourceObservationRun = 'canonical_source_observation_run';
    case Missing = 'missing';
}
