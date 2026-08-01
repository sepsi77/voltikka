<?php

namespace Tests\Unit;

use App\Enums\ContractType;
use App\Enums\MeteringType;
use App\Enums\PricingModel;
use App\Enums\TargetGroup;
use App\Models\ElectricityContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContractClassificationEnumsTest extends TestCase
{
    #[DataProvider('pricingModels')]
    public function test_pricing_model_accepts_supported_values_case_and_whitespace(string $source, PricingModel $expected): void
    {
        $this->assertSame($expected, PricingModel::fromSource($source));
    }

    public static function pricingModels(): array
    {
        return [
            ['Spot', PricingModel::Spot],
            [' spot ', PricingModel::Spot],
            ['FixedPrice', PricingModel::FixedPrice],
            ["\tFIXEDPRICE\n", PricingModel::FixedPrice],
            ['Hybrid', PricingModel::Hybrid],
            [' hYbRiD ', PricingModel::Hybrid],
            ['Unknown', PricingModel::Unknown],
        ];
    }

    #[DataProvider('contractTypes')]
    public function test_contract_type_accepts_supported_values_case_and_whitespace(string $source, ContractType $expected): void
    {
        $this->assertSame($expected, ContractType::fromSource($source));
    }

    public static function contractTypes(): array
    {
        return [
            ['OpenEnded', ContractType::OpenEnded],
            [' openended ', ContractType::OpenEnded],
            ['FixedTerm', ContractType::FixedTerm],
            ["\tFIXEDTERM\n", ContractType::FixedTerm],
            ['Unknown', ContractType::Unknown],
        ];
    }

    #[DataProvider('targetGroups')]
    public function test_target_group_accepts_supported_values_case_and_whitespace(string $source, TargetGroup $expected): void
    {
        $this->assertSame($expected, TargetGroup::fromSource($source));
    }

    public static function targetGroups(): array
    {
        return [
            ['Household', TargetGroup::Household],
            [' household ', TargetGroup::Household],
            ['Company', TargetGroup::Company],
            ["\tCOMPANY\n", TargetGroup::Company],
            ['Both', TargetGroup::Both],
            [' bOtH ', TargetGroup::Both],
            ['Unknown', TargetGroup::Unknown],
        ];
    }

    public function test_verified_aliases_normalize_to_supported_values(): void
    {
        $this->assertSame(PricingModel::FixedPrice, PricingModel::fromSource(' fixed '));
        $this->assertSame(ContractType::FixedTerm, ContractType::fromSource(' FIXED '));
        $this->assertSame(TargetGroup::Household, TargetGroup::fromSource(' consumer '));
    }

    #[DataProvider('unsupportedSources')]
    public function test_classification_enums_return_unknown_for_null_malformed_or_unsupported_values(mixed $source): void
    {
        $this->assertSame(PricingModel::Unknown, PricingModel::fromSource($source));
        $this->assertSame(ContractType::Unknown, ContractType::fromSource($source));
        $this->assertSame(TargetGroup::Unknown, TargetGroup::fromSource($source));
    }

    public static function unsupportedSources(): array
    {
        return [
            [null],
            [''],
            ['Other'],
            [123],
            [[]],
            [new \stdClass],
        ];
    }

    public function test_unknown_pricing_model_is_neither_spot_nor_hybrid(): void
    {
        $model = PricingModel::fromSource('FuturePricing');

        $this->assertSame(PricingModel::Unknown, $model);
        $this->assertNotSame(PricingModel::Spot, $model);
        $this->assertNotSame(PricingModel::Hybrid, $model);
    }

    public function test_publishable_values_exclude_unknown(): void
    {
        $this->assertSame(['Spot', 'FixedPrice', 'Hybrid'], PricingModel::publishableValues());
        $this->assertSame(['OpenEnded', 'FixedTerm'], ContractType::publishableValues());
        $this->assertSame(['Household', 'Company', 'Both'], TargetGroup::publishableValues());
    }

    #[DataProvider('meteringSources')]
    public function test_metering_tolerant_parser_accepts_supported_values_and_seasonal_alias(string $source, MeteringType $expected): void
    {
        $this->assertSame($expected, MeteringType::fromSource($source));
    }

    public static function meteringSources(): array
    {
        return [
            ['General', MeteringType::General],
            [' general ', MeteringType::General],
            ['TIME', MeteringType::Time],
            ["\tSeason\n", MeteringType::Season],
            ['seasonal', MeteringType::Season],
        ];
    }

    #[DataProvider('unsupportedSources')]
    public function test_metering_tolerant_parser_returns_null_for_malformed_or_unknown_values(mixed $source): void
    {
        $this->assertNull(MeteringType::fromSource($source));
    }

    public function test_metering_from_string_preserves_general_fallback(): void
    {
        $this->assertSame(MeteringType::Season, MeteringType::fromString('Seasonal'));
        $this->assertSame(MeteringType::General, MeteringType::fromString('unsupported'));
        $this->assertSame(MeteringType::General, MeteringType::fromString(null));
    }

    public function test_contract_typed_methods_do_not_change_scalar_attributes(): void
    {
        $contract = new ElectricityContract([
            'pricing_model' => 'unsupported',
            'contract_type' => 'Fixed',
            'target_group' => 'Consumer',
            'metering' => 'Seasonal',
        ]);

        $this->assertSame(PricingModel::Unknown, $contract->pricingModelType());
        $this->assertSame(ContractType::FixedTerm, $contract->contractTypeValue());
        $this->assertSame(TargetGroup::Household, $contract->targetGroupType());
        $this->assertSame(MeteringType::Season, $contract->meteringType());
        $this->assertSame('unsupported', $contract->pricing_model);
        $this->assertSame('Fixed', $contract->contract_type);
        $this->assertSame('Consumer', $contract->target_group);
        $this->assertSame('Seasonal', $contract->metering);
    }
}
