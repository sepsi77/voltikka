<?php

namespace App\Services\ContractDetail;

use App\Enums\ContractType;
use App\Enums\MeteringType;
use App\Enums\PricingModel;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;

final class ContractDetailSeoPresenter
{
    /**
     * @return array{
     *     pageTitle: string,
     *     ogTitle: string,
     *     metaDescription: string,
     *     canonicalUrl: string,
     *     webPageSchema: array<string, mixed>,
     *     productSchema: array<string, mixed>,
     *     breadcrumbSchema: array<string, mixed>,
     *     faqSchema: array<string, mixed>
     * }
     */
    public function present(ContractDetailPresentationInput $input): array
    {
        $pageTitle = $this->pageTitle($input);
        $metaDescription = $this->metaDescription($input);

        return [
            'pageTitle' => $pageTitle,
            'ogTitle' => $this->ogTitle($input),
            'metaDescription' => $metaDescription,
            'canonicalUrl' => $input->canonicalUrl,
            'webPageSchema' => $this->webPageSchema($input, $pageTitle, $metaDescription),
            'productSchema' => $this->productSchema($input, $metaDescription),
            'breadcrumbSchema' => $this->breadcrumbSchema($input),
            'faqSchema' => $this->faqSchema($input),
        ];
    }

    private function pageTitle(ContractDetailPresentationInput $input): string
    {
        $contract = $input->contract;
        if (! $contract) {
            return 'Sähkösopimus | Voltikka';
        }

        $name = $this->truncateName($input->displayName);

        if (! $input->isActive) {
            return "{$name} ei ole enää saatavilla | Voltikka";
        }

        $comparisonTitle = $this->comparisonPageTitle($input);
        if ($comparisonTitle !== null) {
            return $comparisonTitle;
        }

        $companyName = $this->company($contract)?->name ?? '';

        return "{$name} — {$companyName} | Voltikka";
    }

    private function comparisonPageTitle(ContractDetailPresentationInput $input): ?string
    {
        $contract = $input->contract;
        if (! $contract || ! $input->priceRank || ! $input->totalContracts) {
            return null;
        }

        $pricePhrase = $this->titlePricePhrase($input->currentDisplayValues);
        $savings = $this->cheapestSavings($input);

        if ($input->priceRank > 25 && $savings !== null && $savings > 0) {
            return $this->buildBudgetedTitle($this->formatEuro($savings).' kalliimpi kuin halvin', $input->displayName);
        }

        if ($pricePhrase === null) {
            return null;
        }

        $change = $this->generalPriceHistoryChange($input->relationalPriceHistory);
        if ($change !== null && abs($change['percent']) >= 25 && $input->priceRank > 25) {
            $subject = $contract->pricingModelType() === PricingModel::Spot ? 'Marginaali' : 'Hinta';
            $direction = $change['percent'] < 0 ? 'laskenut' : 'noussut';
            $percent = number_format(abs($change['percent']), 0, ',', ' ').' %';

            return $this->buildBudgetedTitle("{$subject} {$direction} {$percent}", $input->displayName);
        }

        return $this->buildBudgetedTitle(
            "Sija {$input->priceRank}/{$input->totalContracts} · {$pricePhrase}",
            $input->displayName,
        );
    }

    /**
     * @param  array{general: ?float, day: ?float, night: ?float, winter: ?float, other: ?float, margin: ?float, fee: ?float, package_included_kwh: ?float, package_excess_rate: ?float}  $current
     */
    private function titlePricePhrase(array $current): ?string
    {
        if ($current['margin'] !== null) {
            return 'Marg. '.$this->formatCents($current['margin']).' c/kWh';
        }

        if ($current['general'] !== null) {
            return $this->formatCents($current['general']).' c/kWh';
        }

        if ($current['day'] !== null) {
            return 'Päivä '.$this->formatCents($current['day']).' c/kWh';
        }

        if ($current['winter'] !== null) {
            return 'Talvi '.$this->formatCents($current['winter']).' c/kWh';
        }

        return null;
    }

    private function ogTitle(ContractDetailPresentationInput $input): string
    {
        $contract = $input->contract;
        if (! $contract) {
            return 'Sähkösopimus | Voltikka';
        }

        $name = $this->truncateName($input->displayName);

        if (! $input->isActive) {
            return "{$name} ei ole enää saatavilla | Voltikka";
        }

        if ($input->priceRank) {
            return "{$name} | #{$input->priceRank} halvin | Voltikka";
        }

        $companyName = $this->company($contract)?->name ?? '';

        return "{$name} — {$companyName} | Voltikka";
    }

    private function metaDescription(ContractDetailPresentationInput $input): string
    {
        $contract = $input->contract;
        if (! $contract) {
            return '';
        }

        if (! $input->isActive) {
            return "{$input->displayName} ei ole enää tarjolla. Katso ajantasaiset sähkösopimukset ja vaihtoehdot Voltikasta.";
        }

        $intro = $this->contractMetaIntro($contract, $input->displayName);
        $consumption = $this->formatKwh($input->consumption);
        $historyDescription = $this->priceHistoryMetaDescription($input, $consumption);

        if ($historyDescription !== null) {
            return $historyDescription;
        }

        $totalCost = $this->annualCost($input);
        $cheapestSavings = $this->cheapestSavings($input);

        if ($contract->pricingModelType() !== PricingModel::Spot
            && $totalCost !== null
            && $cheapestSavings !== null
            && $cheapestSavings > 0) {
            return $this->limitMetaDescription(
                "{$intro}. Voltikan vertailussa sen arvioitu hinta on {$this->formatEuro($totalCost)} ensimmäisen 12 kk aikana {$consumption} kulutuksella, ja se on {$this->formatEuro($cheapestSavings)} kalliimpi kuin halvin vaihtoehto."
            );
        }

        if ($input->priceRank && $input->totalContracts) {
            return $this->limitMetaDescription(
                "{$intro}. Voltikan vertailussa se on sijalla {$input->priceRank} / {$input->totalContracts}, kun vuosikulutus on {$consumption}. Katso hinta, sijoitus ja halvemmat vaihtoehdot."
            );
        }

        if ($totalCost !== null) {
            return $this->limitMetaDescription(
                "{$intro}. Arvioitu hinta on {$this->formatEuro($totalCost)} ensimmäisen 12 kk aikana {$consumption} kulutuksella. Katso hinta, ehdot ja vaihtoehdot Voltikassa."
            );
        }

        return $this->limitMetaDescription(
            "{$intro}. Katso hinta, ehdot, sijoitus ja halvemmat vaihtoehdot Voltikassa."
        );
    }

    private function contractMetaIntro(ElectricityContract $contract, string $displayName): string
    {
        $phrase = $this->contractTypePhrase($contract);
        $company = trim((string) ($this->company($contract)?->name ?? $contract->company_name ?? ''));

        return $company !== ''
            ? "{$displayName} on {$phrase} yhtiöltä {$company}"
            : "{$displayName} on {$phrase}";
    }

    private function contractTypePhrase(ElectricityContract $contract): string
    {
        $duration = $this->durationMonthsPhrase($contract);
        $prefix = $duration ? $duration.' ' : '';

        return match ($contract->pricingModelType()) {
            PricingModel::Spot => 'pörssisähkösopimus',
            PricingModel::Hybrid => $prefix.'hybridisähkösopimus',
            PricingModel::FixedPrice => $prefix.'kiinteähintainen sähkösopimus',
            PricingModel::Unknown => match ($contract->meteringType()) {
                MeteringType::Time => $prefix.'aikasähkösopimus',
                MeteringType::Season => $prefix.'kausisähkösopimus',
                default => $prefix.'sähkösopimus',
            },
        };
    }

    private function durationMonthsPhrase(ElectricityContract $contract): ?string
    {
        if ($contract->contractTypeValue() !== ContractType::FixedTerm) {
            return null;
        }

        $value = (string) ($contract->fixed_time_range ?? '');
        $months = match ($value) {
            'Fixed6' => 6,
            'Fixed12' => 12,
            'Fixed24' => 24,
            default => null,
        };

        if ($months === null && preg_match('/(?<!\d)(6|12|24)(?!\d)/', $value, $matches)) {
            $months = (int) $matches[1];
        }

        return $months ? "{$months} kuukauden" : null;
    }

    private function priceHistoryMetaDescription(ContractDetailPresentationInput $input, string $consumption): ?string
    {
        if (! $input->priceRank || ! $input->totalContracts) {
            return null;
        }

        $change = $this->generalPriceHistoryChange($input->relationalPriceHistory);
        if ($change === null || abs($change['percent']) < 3) {
            return null;
        }

        $currentUnitPrice = $input->currentDisplayValues['margin'] ?? $input->currentDisplayValues['general'];
        if ($currentUnitPrice === null) {
            return null;
        }

        $currentPrice = $this->formatCents($currentUnitPrice);
        $monthly = $input->currentDisplayValues['fee'];
        $monthlyFee = $monthly !== null && $monthly >= 0 ? $this->formatEurosPerMonth($monthly) : null;
        $priceNow = $monthlyFee
            ? "{$input->displayName} maksaa nyt {$currentPrice} c/kWh + {$monthlyFee}"
            : "{$input->displayName} maksaa nyt {$currentPrice} c/kWh";

        $subject = $input->currentDisplayValues['margin'] !== null ? 'Marginaali' : 'Energiahinta';
        $direction = $change['percent'] < 0 ? 'laskenut' : 'noussut';
        $percent = number_format(abs($change['percent']), 0, ',', ' ');
        $rankConnector = $input->priceRank > 25 ? 'mutta sopimus on silti' : 'ja sopimus on';

        return $this->limitMetaDescription(
            "{$priceNow}. {$subject} on {$direction} {$percent} % Voltikan seurannassa, {$rankConnector} sijalla {$input->priceRank} / {$input->totalContracts} vertailussa {$consumption} vuosikulutuksella."
        );
    }

    /**
     * @param  array<string, array<array{date: string, price: float|int}>>  $priceHistory
     * @return array{latest: float, earliest: float, percent: float}|null
     */
    private function generalPriceHistoryChange(array $priceHistory): ?array
    {
        $rows = collect($priceHistory['General'] ?? [])
            ->filter(fn (array $row) => is_numeric($row['price'] ?? null) && (float) $row['price'] > 0 && ! empty($row['date']))
            ->sortBy(fn (array $row) => $row['date'])
            ->values();

        if ($rows->count() < 2) {
            return null;
        }

        $earliest = $rows->first();
        $latest = $rows->last();

        if (($earliest['date'] ?? null) === ($latest['date'] ?? null)) {
            return null;
        }

        $earliestPrice = (float) $earliest['price'];
        $latestPrice = (float) $latest['price'];

        if ($earliestPrice <= 0 || $latestPrice <= 0 || abs($latestPrice - $earliestPrice) < 0.0001) {
            return null;
        }

        return [
            'latest' => $latestPrice,
            'earliest' => $earliestPrice,
            'percent' => (($latestPrice - $earliestPrice) / $earliestPrice) * 100,
        ];
    }

    private function annualCost(ContractDetailPresentationInput $input): ?float
    {
        $cost = $input->calculatedCost['total_cost'] ?? null;

        return is_numeric($cost) && is_finite((float) $cost) ? (float) $cost : null;
    }

    private function cheapestSavings(ContractDetailPresentationInput $input): ?float
    {
        $savings = $input->cheaperContractSummary['savings'] ?? null;

        return is_numeric($savings) ? (float) $savings : null;
    }

    /** @return array<string, mixed> */
    private function webPageSchema(ContractDetailPresentationInput $input, string $pageTitle, string $metaDescription): array
    {
        if (! $input->contract || ! $input->isActive) {
            return [];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            '@id' => $input->canonicalUrl.'#webpage',
            'url' => $input->canonicalUrl,
            'name' => $pageTitle,
            'description' => $metaDescription,
            'mainEntity' => [
                '@id' => $input->canonicalUrl.'#product',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function productSchema(ContractDetailPresentationInput $input, string $metaDescription): array
    {
        $contract = $input->contract;
        if (! $contract || ! $input->isActive) {
            return [];
        }

        $company = $this->company($contract);
        $providerId = $input->canonicalUrl.'#provider';
        $offerUrl = $contract->order_link ?: $contract->product_link;

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => $input->canonicalUrl.'#product',
            'name' => $input->displayName,
            'description' => $metaDescription,
            'url' => $input->canonicalUrl,
            'category' => 'Electricity Contract',
        ];

        if ($company) {
            $brand = [
                '@type' => 'Organization',
                '@id' => $providerId,
                'name' => $company->name,
            ];

            if ($logoUrl = $company->getLocalLogoUrl()) {
                $brand['logo'] = $logoUrl;
            }

            if ($company->company_url) {
                $brand['url'] = $company->company_url;
            }

            $schema['brand'] = $brand;
        }

        $offers = [];
        $current = $input->currentDisplayValues;

        $buildOffer = function (string $suffix, string $name, array $priceSpecification) use ($company, $input, $offerUrl, $providerId): array {
            $offer = [
                '@type' => 'Offer',
                '@id' => $input->canonicalUrl.'#offer-'.$suffix,
                'name' => $name,
                'priceSpecification' => $priceSpecification,
            ];

            if ($offerUrl) {
                $offer['url'] = $offerUrl;
            }

            if ($company) {
                $provider = ['@id' => $providerId];
                $offer['offeredBy'] = $provider;
                $offer['seller'] = $provider;
            }

            return $offer;
        };

        $unitOffer = static fn (float $price, string $unitCode, string $unitText): array => [
            '@type' => 'UnitPriceSpecification',
            'price' => $price,
            'priceCurrency' => 'EUR',
            'unitCode' => $unitCode,
            'unitText' => $unitText,
        ];

        $offerFacts = [
            ['margin', 'spot-margin', 'Spot-marginaali', 'KWH', 'c/kWh'],
            ['fee', 'monthly-fee', 'Perusmaksu', 'MON', 'EUR/kk'],
            ['general', 'energy-price', 'Energiahinta', 'KWH', 'c/kWh'],
            ['package_excess_rate', 'package-excess', 'Ylittävä kulutus', 'KWH', 'c/kWh'],
            ['day', 'daytime', 'Päiväsähkö (07:00-22:00)', 'KWH', 'c/kWh'],
            ['night', 'nighttime', 'Yösähkö (22:00-07:00)', 'KWH', 'c/kWh'],
            ['winter', 'seasonal-winter', 'Talvihinta (marras-maaliskuu)', 'KWH', 'c/kWh'],
            ['other', 'seasonal-other', 'Muu aika', 'KWH', 'c/kWh'],
        ];

        foreach ($offerFacts as [$key, $suffix, $name, $unitCode, $unitText]) {
            if ($current[$key] !== null) {
                $offers[] = $buildOffer($suffix, $name, $unitOffer($current[$key], $unitCode, $unitText));
            }
        }

        if (! empty($offers) && ! $input->isPricingExcluded) {
            $schema['offers'] = $offers;
        }

        $additionalProperties = [
            [
                '@type' => 'PropertyValue',
                'name' => 'pricingModel',
                'value' => $this->pricingModelLabel($contract),
            ],
            [
                '@type' => 'PropertyValue',
                'name' => 'contractType',
                'value' => $this->contractTypeLabel($contract),
            ],
            [
                '@type' => 'PropertyValue',
                'name' => 'meteringType',
                'value' => $this->meteringLabel($contract),
            ],
        ];

        if ($current['package_included_kwh'] !== null && ! $input->isPricingExcluded) {
            $additionalProperties[] = [
                '@type' => 'PropertyValue',
                'name' => 'includedEnergyPerMonth',
                'value' => $current['package_included_kwh'],
                'unitText' => 'kWh/kk',
            ];
        }

        $source = $this->electricitySource($contract);
        if ($source) {
            $sourceFacts = [
                ['renewable_total', 'renewablePercentage', false],
                ['nuclear_total', 'nuclearPercentage', false],
                ['fossil_total', 'fossilPercentage', false],
                ['renewable_wind', 'windPowerPercentage', true],
                ['renewable_hydro', 'hydroPowerPercentage', true],
            ];

            foreach ($sourceFacts as [$attribute, $name, $positiveOnly]) {
                $value = $source->{$attribute};
                if ($value !== null && (! $positiveOnly || $value > 0)) {
                    $additionalProperties[] = [
                        '@type' => 'PropertyValue',
                        'name' => $name,
                        'value' => $value,
                        'unitCode' => 'P1',
                        'unitText' => '%',
                    ];
                }
            }
        }

        if (! empty($input->co2Facts) && isset($input->co2Facts['emission_factor_g_per_kwh'])) {
            $additionalProperties[] = [
                '@type' => 'PropertyValue',
                'name' => 'emissionFactor',
                'value' => $input->co2Facts['emission_factor_g_per_kwh'],
                'unitCode' => 'GRM',
                'unitText' => 'gCO2/kWh',
            ];
        }

        if (! empty($additionalProperties)) {
            $schema['additionalProperty'] = $additionalProperties;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private function breadcrumbSchema(ContractDetailPresentationInput $input): array
    {
        $contract = $input->contract;
        if (! $contract || ! $input->isActive) {
            return [];
        }

        $company = $this->company($contract);
        $breadcrumbs = [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Etusivu',
                'item' => $input->applicationUrl,
            ],
            [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => 'Sähkösopimukset',
                'item' => $input->applicationUrl.'/sahkosopimus',
            ],
        ];

        if ($company) {
            $breadcrumbs[] = [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $company->name,
                'item' => $input->applicationUrl.'/sahkosopimus/sahkoyhtiot/'.$company->name_slug,
            ];
            $breadcrumbs[] = [
                '@type' => 'ListItem',
                'position' => 4,
                'name' => $input->displayName,
                'item' => $input->canonicalUrl,
            ];
        } else {
            $breadcrumbs[] = [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $input->displayName,
                'item' => $input->canonicalUrl,
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $breadcrumbs,
        ];
    }

    /** @return array<string, mixed> */
    private function faqSchema(ContractDetailPresentationInput $input): array
    {
        if (! $input->contract || ! $input->isActive || empty($input->faqItems)) {
            return [];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            '@id' => $input->canonicalUrl.'#faq',
            'mainEntity' => array_map(fn (array $item): array => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item['answer'],
                ],
            ], $input->faqItems),
        ];
    }

    private function pricingModelLabel(ElectricityContract $contract): string
    {
        return match ($contract->pricing_model) {
            PricingModel::Spot->value => 'Pörssisähkö',
            PricingModel::FixedPrice->value => 'Kiinteä hinta',
            PricingModel::Hybrid->value => 'Hybridisähkö',
            default => (string) $contract->pricing_model,
        };
    }

    private function contractTypeLabel(ElectricityContract $contract): string
    {
        return match ($contract->contract_type) {
            ContractType::OpenEnded->value => 'Toistaiseksi voimassa',
            ContractType::FixedTerm->value => 'Määräaikainen',
            default => (string) $contract->contract_type,
        };
    }

    private function meteringLabel(ElectricityContract $contract): string
    {
        return match ($contract->metering) {
            MeteringType::General->value => 'Yleissähkö',
            MeteringType::Time->value => 'Aikasähkö',
            MeteringType::Season->value => 'Kausisähkö',
            default => (string) $contract->metering,
        };
    }

    private function company(ElectricityContract $contract): ?Company
    {
        return $contract->relationLoaded('company') ? $contract->getRelation('company') : null;
    }

    private function electricitySource(ElectricityContract $contract): ?ElectricitySource
    {
        return $contract->relationLoaded('electricitySource') ? $contract->getRelation('electricitySource') : null;
    }

    private function truncateName(string $name, int $maxLength = 40): string
    {
        if (mb_strlen($name) <= $maxLength) {
            return $name;
        }

        $cut = mb_substr($name, 0, $maxLength);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace > 20) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, ' -').'…';
    }

    private function buildBudgetedTitle(string $prefix, string $name): string
    {
        $suffix = ' | Voltikka';
        $separator = ' | ';
        $targetLength = 75;
        $minimumNameBudget = 24;
        $availableNameLength = $targetLength - mb_strlen($prefix) - mb_strlen($separator) - mb_strlen($suffix);
        $nameBudget = max($minimumNameBudget, $availableNameLength);
        $titleName = $this->truncateName($name, $nameBudget);

        return "{$prefix}{$separator}{$titleName}{$suffix}";
    }

    private function formatEuro(float $value): string
    {
        return number_format((int) round($value), 0, ',', ' ').' €';
    }

    private function formatCents(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    private function formatEurosPerMonth(float $value): string
    {
        return number_format($value, 2, ',', ' ').' €/kk';
    }

    private function formatKwh(int $value): string
    {
        return number_format($value, 0, ',', ' ').' kWh';
    }

    private function limitMetaDescription(string $description, int $maxLength = 260): string
    {
        $description = trim(preg_replace('/\s+/', ' ', $description) ?? $description);

        if (mb_strlen($description) <= $maxLength) {
            return $description;
        }

        $cut = mb_substr($description, 0, $maxLength - 1);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 80) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, ' .,;:-').'…';
    }
}
