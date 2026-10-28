<?php

namespace App\Services\Analytics;

use App\Models\ContractOrderClick;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ContractOrderClickHandler
{
    use NormalizesAnalyticsInput;

    public function __construct(
        private readonly ContractOrderClickContextSigner $contextSigner,
    ) {}

    /** @param array<string, mixed> $envelope */
    public function handle(array $envelope): void
    {
        $validated = Validator::make($envelope, [
            'event_uuid' => ['required', 'uuid'],
            'context' => ['required', 'string', 'max:8192'],
            'attribution' => ['required', 'array:source,medium,campaign,landing_path'],
            'attribution.source' => ['required', 'string', 'max:2048'],
            'attribution.medium' => ['required', 'string', 'max:2048'],
            'attribution.campaign' => ['present', 'nullable', 'string', 'max:2048'],
            'attribution.landing_path' => ['required', 'string', 'max:2048'],
            'page_path' => ['required', 'string', 'max:2048'],
            'placement' => ['required', Rule::enum(ContractOrderClickPlacement::class)],
        ])->validate();

        try {
            $context = $this->contextSigner->verify($validated['context']);
        } catch (InvalidSignedContext $exception) {
            throw ValidationException::withMessages([
                'context' => $exception->getMessage(),
            ]);
        }

        $now = now()->utc();

        ContractOrderClick::query()->createOrFirst(
            ['event_uuid' => $validated['event_uuid']],
            [
                'occurred_at' => $now,
                'contract_id' => $context->contractId,
                'contract_name' => $context->contractName,
                'company_name' => $context->companyName,
                'annual_price_eur' => $context->annualPriceEur,
                'consumption_kwh' => $context->consumptionKwh,
                'price_rank' => $context->priceRank,
                'rank_total' => $context->rankTotal,
                'rank_consumption_kwh' => $context->rankConsumptionKwh,
                'is_estimate' => $context->isEstimate,
                'pricing_basis' => $context->pricingBasis,
                'cta_location' => $validated['placement'],
                ...$this->attributionColumns($validated['attribution']),
                'page_path' => $this->normalizePath($validated['page_path']),
            ],
        );
    }
}
