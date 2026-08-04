<?php

namespace App\Services\Analytics;

use App\Models\ContractOrderClick;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ContractOrderClickHandler
{
    private const ATTRIBUTION_LENGTH = 100;

    private const CAMPAIGN_LENGTH = 150;

    private const PATH_LENGTH = 500;

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
        $campaign = $this->normalizeText($validated['attribution']['campaign'], self::CAMPAIGN_LENGTH);

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
                'session_source' => $this->normalizeText($validated['attribution']['source'], self::ATTRIBUTION_LENGTH, 'direct'),
                'session_medium' => $this->normalizeText($validated['attribution']['medium'], self::ATTRIBUTION_LENGTH, '(none)'),
                'session_campaign' => $campaign === '' ? null : $campaign,
                'landing_path' => $this->normalizePath($validated['attribution']['landing_path']),
                'page_path' => $this->normalizePath($validated['page_path']),
            ],
        );
    }

    private function normalizeText(mixed $value, int $maximumLength, string $fallback = ''): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $normalized = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $normalized = Str::lower(Str::squish($normalized ?? ''));

        if ($normalized === '') {
            return $fallback;
        }

        return Str::limit($normalized, $maximumLength, '');
    }

    private function normalizePath(mixed $value): string
    {
        if (! is_string($value)) {
            return '/';
        }

        $path = preg_split('/[?#]/', $value, 2)[0] ?? '';
        $path = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($path)) ?? '';

        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }

        return Str::limit($path, self::PATH_LENGTH, '');
    }
}
