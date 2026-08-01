<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Services\ContractDetail\ContractDetailPresentationInput;
use App\Services\ContractDetail\ContractDetailSeoPresenter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContractDetailSeoPresenterTest extends TestCase
{
    public function test_it_presents_active_and_inactive_metadata_without_livewire(): void
    {
        $contract = $this->contract();
        $presenter = app(ContractDetailSeoPresenter::class);
        $active = $presenter->present($this->input(
            contract: $contract,
            isActive: true,
            current: $this->currentValues(general: 7.25, fee: 2.95),
            priceHistory: [
                'General' => [
                    ['date' => '2026-01-01', 'price' => 8.0],
                    ['date' => '2026-02-01', 'price' => 7.25],
                ],
            ],
        ));
        $inactive = $presenter->present($this->input(contract: $contract, isActive: false));

        $this->assertSame('Sija 3/50 · 7,25 c/kWh | Vakaa Sähkö 12 kk | Voltikka', $active['pageTitle']);
        $this->assertSame('Vakaa Sähkö 12 kk | #3 halvin | Voltikka', $active['ogTitle']);
        $this->assertStringContainsString('Vakaa Sähkö 12 kk maksaa nyt 7,25 c/kWh + 2,95 €/kk', $active['metaDescription']);
        $this->assertStringContainsString('Energiahinta on laskenut 9 % Voltikan seurannassa', $active['metaDescription']);
        $this->assertSame('Vakaa Sähkö 12 kk ei ole enää saatavilla | Voltikka', $inactive['pageTitle']);
        $this->assertSame(
            'Vakaa Sähkö 12 kk ei ole enää tarjolla. Katso ajantasaiset sähkösopimukset ja vaihtoehdot Voltikasta.',
            $inactive['metaDescription'],
        );
        $this->assertSame([], $inactive['webPageSchema']);
        $this->assertSame([], $inactive['productSchema']);
        $this->assertSame([], $inactive['breadcrumbSchema']);
        $this->assertSame([], $inactive['faqSchema']);
    }

    public function test_product_offers_use_only_supplied_canonical_values_and_excluded_contracts_have_no_offers(): void
    {
        $contract = $this->contract();
        $presenter = app(ContractDetailSeoPresenter::class);
        $current = $this->currentValues(general: 8.4, fee: 4.2);

        $included = $presenter->present($this->input(
            contract: $contract,
            current: $current,
            calculatedCost: ['total_cost' => 470.4],
        ));
        $excluded = $presenter->present($this->input(
            contract: $contract,
            current: $current,
            calculatedCost: [],
            isPricingExcluded: true,
        ));
        $offers = collect($included['productSchema']['offers'])->keyBy('name');

        $this->assertSame(['Perusmaksu', 'Energiahinta'], $offers->keys()->all());
        $this->assertSame(8.4, $offers['Energiahinta']['priceSpecification']['price']);
        $this->assertSame(4.2, $offers['Perusmaksu']['priceSpecification']['price']);
        $this->assertArrayNotHasKey('offers', $excluded['productSchema']);
    }

    public function test_product_brand_uses_only_a_local_logo(): void
    {
        Storage::fake('public');

        $company = $this->company([
            'logo_url' => 'https://seller.example/external-logo.png',
            'local_logo_path' => null,
        ]);
        $contract = $this->contract($company);
        $presenter = app(ContractDetailSeoPresenter::class);

        $externalOnly = $presenter->present($this->input(contract: $contract));
        $this->assertArrayNotHasKey('logo', $externalOnly['productSchema']['brand']);

        Storage::disk('public')->put('logos/test-energia.webp', 'logo');
        $company->local_logo_path = 'logos/test-energia.png';
        $local = $presenter->present($this->input(contract: $contract));

        $this->assertStringContainsString(
            '/storage/logos/test-energia.webp',
            $local['productSchema']['brand']['logo'],
        );
        $this->assertNotSame($company->logo_url, $local['productSchema']['brand']['logo']);
    }

    public function test_webpage_and_breadcrumb_schemas_keep_the_existing_shape(): void
    {
        $presentation = app(ContractDetailSeoPresenter::class)->present($this->input(contract: $this->contract()));

        $this->assertSame([
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            '@id' => 'https://voltikka.test/sahkosopimus/sopimus/test-contract#webpage',
            'url' => 'https://voltikka.test/sahkosopimus/sopimus/test-contract',
            'name' => 'Sija 3/50 · 7,25 c/kWh | Vakaa Sähkö 12 kk | Voltikka',
            'description' => $presentation['metaDescription'],
            'mainEntity' => [
                '@id' => 'https://voltikka.test/sahkosopimus/sopimus/test-contract#product',
            ],
        ], $presentation['webPageSchema']);
        $this->assertSame('BreadcrumbList', $presentation['breadcrumbSchema']['@type']);
        $this->assertSame(
            ['Etusivu', 'Sähkösopimukset', 'Test Energia Oy', 'Vakaa Sähkö 12 kk'],
            array_column($presentation['breadcrumbSchema']['itemListElement'], 'name'),
        );
        $this->assertSame(
            'https://voltikka.test/sahkosopimus/sahkoyhtiot/test-energia-oy',
            $presentation['breadcrumbSchema']['itemListElement'][2]['item'],
        );
        $this->assertSame(
            'https://voltikka.test/sahkosopimus/sopimus/test-contract',
            $presentation['canonicalUrl'],
        );
    }

    public function test_faq_schema_contains_exactly_the_supplied_visible_items(): void
    {
        $items = [
            ['id' => 'faq-hinta', 'question' => 'Paljonko sopimus maksaa?', 'answer' => 'Arviolta 40 euroa kuukaudessa.'],
            ['id' => 'faq-miten', 'question' => 'Miten hinta toimii?', 'answer' => 'Hinta on kiinteä 12 kuukautta.'],
        ];

        $schema = app(ContractDetailSeoPresenter::class)
            ->present($this->input(contract: $this->contract(), faqItems: $items))['faqSchema'];

        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('FAQPage', $schema['@type']);
        $this->assertSame('https://voltikka.test/sahkosopimus/sopimus/test-contract#faq', $schema['@id']);
        $this->assertSame(
            array_column($items, 'question'),
            array_column($schema['mainEntity'], 'name'),
        );
        $this->assertSame(
            array_column($items, 'answer'),
            array_column(array_column($schema['mainEntity'], 'acceptedAnswer'), 'text'),
        );
    }

    private function contract(?Company $company = null): ElectricityContract
    {
        $company ??= $this->company();
        $contract = new ElectricityContract([
            'id' => 'test-contract',
            'company_name' => $company->name,
            'name' => 'Vakaa Sähkö 12 kk',
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'FixedTerm',
            'fixed_time_range' => 'Fixed12',
            'metering' => 'General',
            'order_link' => 'https://seller.example/order',
            'product_link' => 'https://seller.example/product',
        ]);
        $contract->setRelation('company', $company);
        $contract->setRelation('electricitySource', null);

        return $contract;
    }

    /** @param array<string, mixed> $overrides */
    private function company(array $overrides = []): Company
    {
        return new Company(array_merge([
            'name' => 'Test Energia Oy',
            'name_slug' => 'test-energia-oy',
            'company_url' => 'https://seller.example',
            'logo_url' => null,
            'local_logo_path' => null,
        ], $overrides));
    }

    /**
     * @param  array{general: ?float, day: ?float, night: ?float, winter: ?float, other: ?float, margin: ?float, fee: ?float, package_included_kwh: ?float, package_excess_rate: ?float}|null  $current
     * @param  array<string, mixed>  $calculatedCost
     * @param  array<string, array<array{date: string, price: float|int}>>  $priceHistory
     * @param  list<array{id: string, question: string, answer: string}>  $faqItems
     */
    private function input(
        ElectricityContract $contract,
        bool $isActive = true,
        ?array $current = null,
        array $calculatedCost = ['total_cost' => 470.4],
        array $priceHistory = [],
        bool $isPricingExcluded = false,
        array $faqItems = [],
    ): ContractDetailPresentationInput {
        return new ContractDetailPresentationInput(
            contract: $contract,
            isActive: $isActive,
            displayName: 'Vakaa Sähkö 12 kk',
            priceRank: 3,
            totalContracts: 50,
            consumption: 5000,
            currentDisplayValues: $current ?? $this->currentValues(general: 7.25),
            calculatedCost: $calculatedCost,
            relationalPriceHistory: $priceHistory,
            cheaperContractSummary: null,
            isPricingExcluded: $isPricingExcluded,
            co2Facts: ['emission_factor_g_per_kwh' => 12.5],
            canonicalUrl: 'https://voltikka.test/sahkosopimus/sopimus/test-contract',
            applicationUrl: 'https://voltikka.test',
            faqItems: $faqItems,
        );
    }

    /**
     * @return array{general: ?float, day: ?float, night: ?float, winter: ?float, other: ?float, margin: ?float, fee: ?float, package_included_kwh: ?float, package_excess_rate: ?float}
     */
    private function currentValues(?float $general = null, ?float $fee = null): array
    {
        return [
            'general' => $general,
            'day' => null,
            'night' => null,
            'winter' => null,
            'other' => null,
            'margin' => null,
            'fee' => $fee,
            'package_included_kwh' => null,
            'package_excess_rate' => null,
        ];
    }
}
