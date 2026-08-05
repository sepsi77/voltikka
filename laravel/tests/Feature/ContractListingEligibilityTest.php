<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\Postcode;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContractListingEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create([
            'name' => 'Eligibility Energia Oy',
            'name_slug' => 'eligibility-energia-oy',
        ]);

        $this->createPostcode('00100', 'Helsinki');
        $this->createPostcode('33100', 'Tampere');
    }

    public function test_default_lists_exclude_regional_contracts(): void
    {
        $this->createContract('national', true, 5.0);
        $this->createContract('regional', false, 4.0, ['00100']);

        $baseIds = $this->listingIds(Livewire::test('contracts-list')->viewData('contracts'));
        $seoIds = $this->listingIds(Livewire::test('seo-contracts-list')->viewData('contracts'));

        $cheapest = Livewire::test('cheapest-contracts');
        $cheapestIds = collect([$cheapest->viewData('featuredContract')?->id])
            ->merge($this->listingIds($cheapest->viewData('contracts')))
            ->filter()
            ->values();

        foreach ([$baseIds, $seoIds, $cheapestIds] as $ids) {
            $this->assertSame(['national'], $ids->all());
        }
    }

    public function test_exact_postcode_adds_only_matching_regional_contracts(): void
    {
        $this->createContract('national', true, 5.0);
        $this->createContract('helsinki-regional', false, 4.0, ['00100']);
        $this->createContract('tampere-regional', false, 3.0, ['33100']);

        $helsinki = Livewire::test('contracts-list')
            ->call('selectPostcode', ' 00100 ')
            ->assertSet('postcodeFilter', '00100')
            ->assertSet('page', 1);

        $helsinki->assertDispatched('postcode-preference-stored', function (string $name, array $params): bool {
            return ($params['postcode'] ?? null) === '00100';
        });

        $this->assertEqualsCanonicalizing(
            ['national', 'helsinki-regional'],
            $this->listingIds($helsinki->viewData('contracts'))->all(),
        );

        $tampereIds = $this->listingIds(
            Livewire::test('contracts-list')
                ->call('selectPostcode', '33100')
                ->viewData('contracts'),
        );

        $this->assertEqualsCanonicalizing(['national', 'tampere-regional'], $tampereIds->all());
        $this->assertFalse($tampereIds->contains('helsinki-regional'));
    }

    public function test_invalid_and_stale_postcode_actions_fail_closed(): void
    {
        $this->createContract('national', true, 5.0);
        $this->createContract('regional', false, 4.0, ['00100']);

        $invalid = Livewire::test('contracts-list')
            ->set('page', 3)
            ->set('postcodeSearch', '12x')
            ->call('applyPostcodeSearch')
            ->assertSet('postcodeFilter', '')
            ->assertSet('postcodeError', 'Postinumeron pitää olla viisi numeroa.')
            ->assertSet('page', 1)
            ->assertDispatched('postcode-preference-removed');

        $invalid->set('postcodeSearch', '1')->assertSet('postcodeError', null);

        $restored = Livewire::test('contracts-list')
            ->set('page', 3)
            ->call('restorePostcode', '00100')
            ->assertSet('postcodeFilter', '00100')
            ->assertSet('page', 3)
            ->call('restorePostcode', '99999')
            ->assertSet('postcodeFilter', '')
            ->assertSet('postcodeError', 'Postinumeroa ei löytynyt. Tarkista numero ja yritä uudelleen.')
            ->assertSet('page', 3)
            ->assertDispatched('postcode-preference-removed');

        $nationalOnly = Livewire::test('contracts-list');

        $this->assertSame(['national'], $this->listingIds($nationalOnly->viewData('contracts'))->all());
    }

    public function test_selector_is_visible_and_contains_browser_persistence_markup(): void
    {
        $component = Livewire::test('contracts-list');

        $component
            ->assertSee('Saatavuus: koko Suomi')
            ->assertSee('Lisää postinumero, niin saat mukaan alueelliset sopimukset.')
            ->assertSee('voltikka_postcode_v1', false)
            ->assertSee('data-search-input', false)
            ->assertSee('wire:model.live.debounce.300ms="postcodeSearch"', false)
            ->assertSee('this.$wire.postcodeFilter', false)
            ->assertSee("localStorage.setItem('voltikka_postcode_v1', current)", false)
            ->assertSee('restorePostcode(saved)', false);

        Livewire::test('cheapest-contracts')
            ->assertSee('Saatavuus: koko Suomi')
            ->assertSee('voltikka_postcode_v1', false);
    }

    private function createPostcode(string $postcode, string $place): void
    {
        Postcode::create([
            'postcode' => $postcode,
            'postcode_fi_name' => $place,
            'postcode_fi_name_slug' => mb_strtolower($place),
            'municipal_name_fi' => $place,
            'municipal_name_fi_slug' => mb_strtolower($place),
        ]);
    }

    private function createContract(
        string $id,
        ?bool $isNational,
        float $price,
        array $postcodes = [],
    ): ElectricityContract {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => 'Eligibility Energia Oy',
            'name' => "Sopimus {$id}",
            'name_slug' => $id,
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'FixedPrice',
            'target_group' => 'Household',
            'availability_is_national' => $isNational,
        ]);

        PriceComponent::create([
            'id' => "price-{$id}",
            'electricity_contract_id' => $id,
            'price_component_type' => 'General',
            'price_date' => now()->toDateString(),
            'price' => $price,
            'payment_unit' => 'c/kWh',
        ]);

        ActiveContract::create(['id' => $id]);

        if ($postcodes !== []) {
            $contract->availabilityPostcodes()->attach($postcodes);
        }

        return $contract;
    }

    private function listingIds(iterable $contracts): \Illuminate\Support\Collection
    {
        if ($contracts instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            $contracts = $contracts->items();
        }

        return collect($contracts)->pluck('id')->sort()->values();
    }
}
