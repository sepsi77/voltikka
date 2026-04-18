<?php

namespace App\Support;

class ContractLabels
{
    private const PRICING_MODEL = [
        'Spot' => 'Pörssisähkö',
        'FixedPrice' => 'Kiinteä hinta',
        'Hybrid' => 'Hybridisähkö',
    ];

    private const CONTRACT_TYPE = [
        'OpenEnded' => 'Toistaiseksi voimassa',
        'FixedTerm' => 'Määräaikainen',
    ];

    private const METERING = [
        'General' => 'Yleissähkö',
        'Time' => 'Aikasähkö',
        'Season' => 'Kausisähkö',
    ];

    private const FIXED_TIME_RANGE = [
        'Below6' => 'Alle 6 kk kiinteä hinta',
        'Fixed6' => '6 kk kiinteä hinta',
        'Between711' => '7–11 kk kiinteä hinta',
        'Fixed12' => '12 kk kiinteä hinta',
        'Between1323' => '13–23 kk kiinteä hinta',
        'Fixed24' => '24 kk kiinteä hinta',
        'Over24' => 'Yli 24 kk kiinteä hinta',
        'Other' => 'Muu määräaika',
    ];

    private const TARGET_GROUP = [
        'Household' => 'Kotitalous',
        'Company' => 'Yritys',
        'Both' => 'Kotitalous ja yritys',
    ];

    public static function pricingModel(?string $value): ?string
    {
        return $value === null ? null : (self::PRICING_MODEL[$value] ?? $value);
    }

    public static function contractType(?string $value): ?string
    {
        return $value === null ? null : (self::CONTRACT_TYPE[$value] ?? $value);
    }

    public static function metering(?string $value): ?string
    {
        return $value === null ? null : (self::METERING[$value] ?? $value);
    }

    public static function fixedTimeRange(?string $value): ?string
    {
        return $value === null ? null : (self::FIXED_TIME_RANGE[$value] ?? $value);
    }

    public static function targetGroup(?string $value): ?string
    {
        return $value === null ? null : (self::TARGET_GROUP[$value] ?? $value);
    }
}
