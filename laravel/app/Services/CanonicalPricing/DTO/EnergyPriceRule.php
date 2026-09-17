<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\EnergyPriceRuleKind;
use App\Services\CanonicalPricing\Exceptions\CanonicalPricingParseException;
use DateTimeImmutable;

/** Evidence stays in private interpretation JSON, not in the calculation DTO. */
final readonly class EnergyPriceRule
{
    public function __construct(
        public EnergyPriceRuleKind $kind = EnergyPriceRuleKind::Unknown,
        public ?PhaseBoundary $starts = null,
        public ?PhaseBoundary $ends = null,
        public ?float $discountValue = null,
        public ?float $floorAmount = null,
        public ?EnergyNormalBasis $normalBasis = null,
    ) {}

    public function withMonetaryMultiplier(float $multiplier): self
    {
        return new self($this->kind, $this->starts, $this->ends,
            $this->discountValue === null ? null : $this->discountValue * ($this->kind === EnergyPriceRuleKind::AbsoluteDiscount ? $multiplier : 1),
            $this->floorAmount === null ? null : $this->floorAmount * $multiplier,
            $this->normalBasis,
        );
    }

    /** Strict shape only. Source proof belongs to the interpretation validator. */
    public static function fromArray(mixed $raw): self
    {
        if ($raw === null) {
            return new self;
        }
        self::object($raw, ['kind', 'starts', 'ends', 'discount_value', 'floor_amount', 'normal_basis', 'evidence']);
        $kind = is_string($raw['kind']) ? EnergyPriceRuleKind::tryFrom($raw['kind']) : null;
        self::require($kind !== null, 'Unknown energy rule kind.');
        self::evidence($raw['evidence']);
        if ($kind === EnergyPriceRuleKind::Unknown) {
            self::require($raw['starts'] === null && $raw['ends'] === null && $raw['discount_value'] === null
                && $raw['floor_amount'] === null && $raw['normal_basis'] === null && $raw['evidence'] === [], 'Unknown energy rule asserts facts.');

            return new self;
        }
        self::require($raw['evidence'] !== [], 'Known energy rule lacks evidence.');
        [$starts, $ends] = self::bounds($raw['starts'], $raw['ends'], $kind === EnergyPriceRuleKind::FixedPrice || $kind->isDiscount());
        $discount = self::number($raw['discount_value']);
        $floor = self::number($raw['floor_amount']);
        $normal = null;
        if ($raw['normal_basis'] !== null) {
            $basis = $raw['normal_basis'];
            self::object($basis, ['kind', 'starts', 'ends', 'evidence']);
            $normalKind = is_string($basis['kind']) ? EnergyPriceRuleKind::tryFrom($basis['kind']) : null;
            self::require(in_array($normalKind, [EnergyPriceRuleKind::Unknown, EnergyPriceRuleKind::FixedPrice, EnergyPriceRuleKind::AdjustableTariff], true), 'Invalid normal energy basis kind.');
            self::evidence($basis['evidence']);
            if ($normalKind === EnergyPriceRuleKind::Unknown) {
                self::require($basis['starts'] === null && $basis['ends'] === null && $basis['evidence'] === [], 'Unknown normal basis asserts facts.');
                $normal = new EnergyNormalBasis;
            } else {
                self::require($basis['evidence'] !== [], 'Known normal basis lacks evidence.');
                [$normalStarts, $normalEnds] = self::bounds($basis['starts'], $basis['ends'], $normalKind === EnergyPriceRuleKind::FixedPrice);
                $normal = new EnergyNormalBasis($normalKind, $normalStarts, $normalEnds);
            }
        }
        if ($kind->isDiscount()) {
            self::require($discount !== null && $discount >= 0 && ($kind !== EnergyPriceRuleKind::PercentageDiscount || $discount <= 100), 'Invalid energy discount operand.');
            self::require($normal !== null && $normal->kind !== EnergyPriceRuleKind::Unknown, 'Discount lacks a known normal basis.');
            self::require($floor === null || $floor >= 0, 'Invalid energy source floor.');
        } else {
            self::require($discount === null && $floor === null, 'Non-discount rule asserts discount operands.');
        }

        return new self($kind, $starts, $ends, $discount, $floor, $normal);
    }

    public static function bounds(mixed $start, mixed $end, bool $finite): array
    {
        $starts = self::boundary($start);
        $ends = self::boundary($end);
        self::require(in_array($starts->kind, [BoundaryKind::ContractStart, BoundaryKind::AfterMonths, BoundaryKind::Date], true), 'Unsupported energy rule start.');
        self::require(in_array($ends->kind, $finite ? [BoundaryKind::AfterMonths, BoundaryKind::Date] : [BoundaryKind::AfterMonths, BoundaryKind::Date, BoundaryKind::None], true), 'Energy rule needs supported finite bounds.');
        if ($ends->kind !== BoundaryKind::None) {
            if ($starts->kind === BoundaryKind::Date || $ends->kind === BoundaryKind::Date) {
                self::require($starts->kind === BoundaryKind::Date && $ends->kind === BoundaryKind::Date && $starts->value <= $ends->value, 'Energy rule dates conflict.');
            } else {
                self::require(($starts->afterMonths() ?? 0) < $ends->afterMonths(), 'Energy rule duration is empty.');
            }
        }

        return [$starts, $ends];
    }

    private static function boundary(mixed $raw): PhaseBoundary
    {
        self::object($raw, ['kind', 'value']);
        $kind = is_string($raw['kind']) ? BoundaryKind::tryFrom($raw['kind']) : null;
        self::require($kind !== null, 'Unknown energy boundary.');
        $value = $raw['value'];
        if ($kind === BoundaryKind::Date) {
            $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            self::require($date !== false && $date->format('Y-m-d') === $value, 'Invalid energy boundary date.');
        } elseif ($kind === BoundaryKind::AfterMonths) {
            self::require(is_string($value) && preg_match('/^(0|[1-9][0-9]{0,2})$/D', $value) === 1, 'Invalid energy boundary duration.');
        } else {
            self::require($value === null, 'Energy boundary has an unexpected value.');
        }

        return new PhaseBoundary($kind, $value);
    }

    private static function object(mixed $raw, array $keys): void
    {
        self::require(is_array($raw) && count($raw) === count($keys) && array_diff($keys, array_keys($raw)) === [], 'Malformed energy rule object.');
    }

    private static function evidence(mixed $raw): void
    {
        self::require(is_array($raw) && array_is_list($raw), 'Malformed energy rule evidence.');
        foreach ($raw as $citation) {
            self::object($citation, ['source', 'quote']);
            self::require(is_string($citation['source']) && $citation['source'] !== '' && is_string($citation['quote']) && $citation['quote'] !== '', 'Malformed energy rule citation.');
        }
    }

    private static function number(mixed $value): ?float
    {
        self::require($value === null || ((is_int($value) || is_float($value)) && is_finite((float) $value)), 'Energy rule number must be finite.');

        return $value === null ? null : (float) $value;
    }

    private static function require(bool $valid, string $message): void
    {
        if (! $valid) {
            throw new CanonicalPricingParseException($message);
        }
    }
}
