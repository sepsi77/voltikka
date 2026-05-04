<?php

namespace App\Support;

use App\Models\Company;
use App\Models\ElectricityContract;

class ContractInternalLinks
{
    public static function companyUrl(?Company $company): ?string
    {
        if (! $company?->name_slug) {
            return null;
        }

        return route('company.detail', ['companySlug' => $company->name_slug], false);
    }

    /**
     * Build the contract-detail hero badge links in the same conceptual order
     * as the badges are shown: duration, metering, pricing model.
     *
     * @return array<int, array{label: string, url: string|null}>
     */
    public static function heroBadgeLinks(ElectricityContract $contract): array
    {
        $badges = [];

        if ($contract->fixed_time_range) {
            $badges[] = [
                'label' => ContractLabels::fixedTimeRange($contract->fixed_time_range) ?? $contract->fixed_time_range,
                'url' => '/sahkosopimus/maaraaikainen',
            ];
        } elseif ($contract->contract_type) {
            $badges[] = [
                'label' => ContractLabels::contractType($contract->contract_type) ?? $contract->contract_type,
                'url' => self::contractTypeUrl($contract->contract_type),
            ];
        }

        if ($contract->metering) {
            $badges[] = [
                'label' => ContractLabels::metering($contract->metering) ?? $contract->metering,
                'url' => self::meteringUrl($contract),
            ];
        }

        if ($contract->pricing_model && $contract->pricing_model !== 'FixedPrice') {
            $badges[] = [
                'label' => ContractLabels::pricingModel($contract->pricing_model) ?? $contract->pricing_model,
                'url' => self::pricingModelUrl($contract->pricing_model),
            ];
        }

        return self::dedupeLinkedBadges($badges);
    }

    protected static function contractTypeUrl(?string $contractType): ?string
    {
        return match ($contractType) {
            'FixedTerm', 'Fixed' => '/sahkosopimus/maaraaikainen',
            'OpenEnded' => '/sahkosopimus/toistaiseksi',
            default => null,
        };
    }

    protected static function meteringUrl(ElectricityContract $contract): ?string
    {
        return match ($contract->metering) {
            'General' => in_array($contract->pricing_model, [null, 'FixedPrice'], true)
                ? '/sahkosopimus/yleissahko'
                : null,
            'Time' => '/sahkosopimus/aikasahko',
            'Season' => '/sahkosopimus/kausisahko',
            default => null,
        };
    }

    protected static function pricingModelUrl(?string $pricingModel): ?string
    {
        return match ($pricingModel) {
            'Spot' => '/sahkosopimus/porssisahko',
            'Hybrid' => '/sahkosopimus/joustosahko',
            default => null,
        };
    }

    /**
     * @param  array<int, array{label: string, url: string|null}>  $badges
     * @return array<int, array{label: string, url: string|null}>
     */
    protected static function dedupeLinkedBadges(array $badges): array
    {
        $seenUrls = [];

        return array_values(array_filter($badges, function (array $badge) use (&$seenUrls): bool {
            if ($badge['url'] === null) {
                return true;
            }

            if (isset($seenUrls[$badge['url']])) {
                return false;
            }

            $seenUrls[$badge['url']] = true;

            return true;
        }));
    }
}
