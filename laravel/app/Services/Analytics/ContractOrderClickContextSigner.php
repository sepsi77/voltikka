<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ContractOrderClickContextSigner
{
    public const VERSION = 1;

    public const LIFETIME_HOURS = 96;

    private const PURPOSE = 'voltikka-contract-order-click-context-v1';

    private const EXPECTED_KEYS = [
        'version',
        'contract_id',
        'contract_name',
        'company_name',
        'annual_price_eur',
        'consumption_kwh',
        'price_rank',
        'rank_total',
        'rank_consumption_kwh',
        'is_estimate',
        'pricing_basis',
        'issued_at',
        'expires_at',
    ];

    public function __construct(
        private readonly AnalyticsContextToken $token,
    ) {}

    public function sign(
        string $contractId,
        string $contractName,
        string $companyName,
        ?float $annualPriceEur,
        int $consumptionKwh,
        ?int $priceRank,
        ?int $rankTotal,
        ?int $rankConsumptionKwh,
        bool $isEstimate,
        ?string $pricingBasis,
        ?CarbonInterface $issuedAt = null,
    ): string {
        $issued = CarbonImmutable::instance($issuedAt ?? now())->utc();
        $expires = $issued->addHours(self::LIFETIME_HOURS);

        $payload = [
            'version' => self::VERSION,
            'contract_id' => $contractId,
            'contract_name' => $contractName,
            'company_name' => $companyName,
            'annual_price_eur' => $annualPriceEur,
            'consumption_kwh' => $consumptionKwh,
            'price_rank' => $priceRank,
            'rank_total' => $rankTotal,
            'rank_consumption_kwh' => $rankConsumptionKwh,
            'is_estimate' => $isEstimate,
            'pricing_basis' => $pricingBasis,
            'issued_at' => $issued->timestamp,
            'expires_at' => $expires->timestamp,
        ];

        return $this->token->encode($payload, self::PURPOSE);
    }

    public function verify(string $token): VerifiedContractOrderClickContext
    {
        $payload = $this->token->decode($token, self::PURPOSE);

        if (array_keys($payload) !== self::EXPECTED_KEYS) {
            throw new InvalidSignedContext('The signed context schema is invalid.');
        }

        $this->validatePayload($payload);

        return new VerifiedContractOrderClickContext(
            contractId: $payload['contract_id'],
            contractName: $payload['contract_name'],
            companyName: $payload['company_name'],
            annualPriceEur: $payload['annual_price_eur'] === null ? null : (float) $payload['annual_price_eur'],
            consumptionKwh: $payload['consumption_kwh'],
            priceRank: $payload['price_rank'],
            rankTotal: $payload['rank_total'],
            rankConsumptionKwh: $payload['rank_consumption_kwh'],
            isEstimate: $payload['is_estimate'],
            pricingBasis: $payload['pricing_basis'],
            issuedAt: CarbonImmutable::createFromTimestampUTC($payload['issued_at']),
            expiresAt: CarbonImmutable::createFromTimestampUTC($payload['expires_at']),
        );
    }

    /** @param array<string, mixed> $payload */
    private function validatePayload(array $payload): void
    {
        if ($payload['version'] !== self::VERSION
            || ! $this->validString($payload['contract_id'], 255)
            || ! $this->validString($payload['contract_name'], 255)
            || ! $this->validString($payload['company_name'], 255)
            || ! $this->validNullableNumber($payload['annual_price_eur'])
            || ! $this->validPositiveInteger($payload['consumption_kwh'])
            || ! $this->validNullablePositiveInteger($payload['price_rank'])
            || ! $this->validNullablePositiveInteger($payload['rank_total'])
            || ! $this->validNullablePositiveInteger($payload['rank_consumption_kwh'])
            || ! is_bool($payload['is_estimate'])
            || ! $this->validNullableString($payload['pricing_basis'], 64)
            || ! $this->validPositiveInteger($payload['issued_at'])
            || ! $this->validPositiveInteger($payload['expires_at'])) {
            throw new InvalidSignedContext('The signed context fields are invalid.');
        }

        if ($payload['price_rank'] !== null && $payload['rank_total'] !== null && $payload['price_rank'] > $payload['rank_total']) {
            throw new InvalidSignedContext('The signed context rank is invalid.');
        }

        $now = CarbonImmutable::now('UTC')->timestamp;

        if ($payload['issued_at'] > ($now + 300)
            || $payload['expires_at'] <= $payload['issued_at']
            || $payload['expires_at'] <= $now
            || ($payload['expires_at'] - $payload['issued_at']) > (self::LIFETIME_HOURS * 3600)) {
            throw new InvalidSignedContext('The signed context time range is invalid.');
        }
    }

    private function validString(mixed $value, int $maximumLength): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= $maximumLength;
    }

    private function validNullableString(mixed $value, int $maximumLength): bool
    {
        return $value === null || $this->validString($value, $maximumLength);
    }

    private function validNullableNumber(mixed $value): bool
    {
        return $value === null || (is_int($value) || is_float($value))
            && is_finite((float) $value)
            && $value >= 0;
    }

    private function validPositiveInteger(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function validNullablePositiveInteger(mixed $value): bool
    {
        return $value === null || $this->validPositiveInteger($value);
    }
}
