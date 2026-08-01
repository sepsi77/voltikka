<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Services\ContractPriceHistory\ContractHistoryPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractHistoryPresenterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Historia Energia Oy',
            'name_slug' => 'historia-energia-oy',
        ]);
    }

    public function test_it_presents_a_backward_chain_with_compatible_keys_order_and_flags(): void
    {
        $current = ElectricityContract::factory()
            ->forCompany($this->company)
            ->active()
            ->withRelationalPrices([
                $this->price('current-general', 'General', '2026-03-15', 5.4, 'c/kWh'),
                $this->price('current-monthly', 'Monthly', '2026-03-15', 2.9, 'EUR/month'),
            ])
            ->create(['id' => 'history-current', 'name' => 'Nykyinen sopimus']);

        $previous = ElectricityContract::factory()
            ->forCompany($this->company)
            ->withRelationalPrices([
                $this->price('previous-general', 'General', '2026-02-10', 6.1, 'c/kWh'),
            ])
            ->create([
                'id' => 'history-previous',
                'name' => 'Edellinen sopimus',
                'replaced_by_contract_id' => $current->id,
            ]);

        $oldest = ElectricityContract::factory()
            ->forCompany($this->company)
            ->withRelationalPrices([
                $this->price('oldest-general', 'General', '2026-01-05', 6.8, 'c/kWh'),
            ])
            ->create([
                'id' => 'history-oldest',
                'name' => 'Vanhin sopimus',
                'replaced_by_contract_id' => $previous->id,
            ]);

        $presentation = app(ContractHistoryPresenter::class)->present($current);

        $this->assertSame(
            ['priceHistory', 'contractHistory', 'priceTypeLabels', 'priceTypeOrder'],
            array_keys($presentation),
        );
        $this->assertSame(['General', 'Monthly'], $presentation['priceTypeOrder']);
        $this->assertSame(
            [$current->id, $previous->id, $oldest->id],
            array_column($presentation['contractHistory'], 'id'),
        );
        $this->assertSame(
            [true, false, false],
            array_column($presentation['contractHistory'], 'is_current'),
        );
        $this->assertSame(
            [true, false, false],
            array_column($presentation['contractHistory'], 'is_active'),
        );
        $this->assertSame(
            ['2026-03-15', '2026-02-10', '2026-01-05'],
            array_column($presentation['priceHistory']['General'], 'date'),
        );
        $this->assertSame(
            [$current->id, $previous->id, $oldest->id],
            array_column($presentation['priceHistory']['General'], 'contract_id'),
        );
    }

    public function test_it_preserves_spot_winter_and_unknown_type_labels(): void
    {
        $contract = ElectricityContract::factory()
            ->forCompany($this->company)
            ->spot()
            ->withRelationalPrices([
                $this->price('spot-unknown', 'NewUpstreamType', '2026-04-01', 1.2, 'c/kWh'),
                $this->price('spot-winter-day', 'SeasonalWinterDay', '2026-04-01', 8.2, 'c/kWh'),
                $this->price('spot-general', 'General', '2026-04-01', 0.6, 'c/kWh'),
                $this->price('spot-winter', 'SeasonalWinter', '2026-04-01', 8.1, 'c/kWh'),
                $this->price('spot-type', 'Spot', '2026-04-01', 0.4, 'c/kWh'),
            ])
            ->create(['id' => 'history-labels']);

        $presentation = app(ContractHistoryPresenter::class)->present($contract);
        $labels = $presentation['priceTypeLabels'];
        $timelineLabels = collect($presentation['contractHistory'][0]['prices'])->pluck('label', 'type');

        $this->assertSame('Marginaali', $labels['General']);
        $this->assertSame('Marginaali', $labels['Spot']);
        $this->assertSame('Talvihinta', $labels['SeasonalWinter']);
        $this->assertSame('Talvihinta', $labels['SeasonalWinterDay']);
        $this->assertSame('NewUpstreamType', $timelineLabels['NewUpstreamType']);
        $this->assertSame(
            ['General', 'Spot', 'SeasonalWinter', 'SeasonalWinterDay', 'NewUpstreamType'],
            $presentation['priceTypeOrder'],
        );
    }

    public function test_it_preserves_promotion_copy_positive_price_preference_and_zero_row_last_seen_semantics(): void
    {
        $contract = ElectricityContract::factory()
            ->forCompany($this->company)
            ->withRelationalPrices([
                [
                    ...$this->price('promotion-positive', 'General', '2026-01-10', 6.2, 'c/kWh'),
                    'has_discount' => true,
                    'discount_value' => 15,
                    'discount_is_percentage' => true,
                    'discount_discount_n_first_months' => 3,
                ],
                $this->price('promotion-zero', 'General', '2026-03-20', 0, 'c/kWh'),
            ])
            ->create(['id' => 'history-promotion']);

        $presentation = app(ContractHistoryPresenter::class)->present($contract);
        $version = $presentation['contractHistory'][0];

        $this->assertSame('2026-01-10', $version['latest_price_date']->toDateString());
        $this->assertSame('2026-03-20', $version['last_seen_on_sale_date']->toDateString());
        $this->assertSame(6.2, $version['prices'][0]['price']);
        $this->assertSame('3 ensimmäistä kuukautta · -15% alennus', $version['promotion']);
        $this->assertSame([0.0, 6.2], array_column($presentation['priceHistory']['General'], 'price'));
    }

    public function test_it_loads_multiple_predecessors_with_a_bounded_query_count(): void
    {
        $current = ElectricityContract::factory()
            ->forCompany($this->company)
            ->active()
            ->withRelationalPrices([
                $this->price('bounded-current', 'General', '2026-05-01', 5.0, 'c/kWh'),
            ])
            ->create(['id' => 'bounded-current']);

        $replacementId = $current->id;
        $firstPredecessorDate = CarbonImmutable::create(2026, 4, 1);

        for ($depth = 1; $depth <= 27; $depth++) {
            $id = 'bounded-predecessor-'.str_pad((string) $depth, 2, '0', STR_PAD_LEFT);
            $date = $firstPredecessorDate->subMonths($depth - 1)->toDateString();
            $predecessor = ElectricityContract::factory()
                ->forCompany($this->company)
                ->withRelationalPrices([
                    $this->price('bounded-price-'.$depth, 'General', $date, 6.0 + $depth, 'c/kWh'),
                ])
                ->create([
                    'id' => $id,
                    'replaced_by_contract_id' => $replacementId,
                ]);

            $replacementId = $predecessor->id;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $presentation = app(ContractHistoryPresenter::class)->present($current);
        $queries = collect(DB::getQueryLog())->pluck('query');

        $historyIds = array_column($presentation['contractHistory'], 'id');

        $this->assertCount(26, $historyIds);
        $this->assertContains('bounded-predecessor-25', $historyIds);
        $this->assertNotContains('bounded-predecessor-26', $historyIds);
        $this->assertNotContains('bounded-predecessor-27', $historyIds);
        $this->assertSame(1, $queries->filter(fn (string $query) => str_contains($query, 'WITH RECURSIVE replacement_chain'))->count());
        $this->assertLessThanOrEqual(1, $queries->filter(fn (string $query) => str_contains($query, 'from "price_components"'))->count());
        $this->assertLessThanOrEqual(1, $queries->filter(fn (string $query) => str_contains($query, 'from "active_contracts"'))->count());
        $this->assertLessThanOrEqual(5, $queries->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function price(string $id, string $type, string $date, float|int $price, string $unit): array
    {
        return [
            'id' => $id,
            'price_component_type' => $type,
            'price_date' => $date,
            'price' => $price,
            'payment_unit' => $unit,
        ];
    }
}
