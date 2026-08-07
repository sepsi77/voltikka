<?php

namespace App\Services\ContractInterpretation\Enums;

enum HistoricalEvidenceGrade: string
{
    case FirstImmutableTextBackcast = 'exact_components_first_immutable_text_backcast';
    case LastObservedTextBackcast = 'exact_components_last_observed_text_backcast';
    case StructuredOnly = 'exact_components_structured_only';
}
