<?php

namespace Tests\Unit\CanonicalPricing;

use App\Enums\MeteringType;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\NormalEnergyProjection;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\ForwardPremium\ForwardPremiumResolver;
use App\Services\CanonicalPricing\ForwardPremium\PremiumCompatibility;
use App\Services\CanonicalPricing\ForwardPremium\PremiumEstimate;
use App\Services\CanonicalPricing\ForwardPremium\PremiumFamily;
use App\Services\CanonicalPricing\ForwardPremium\PremiumObservation;
use App\Services\CanonicalPricing\ForwardPremium\PremiumSource;
use App\Services\CanonicalPricing\ForwardPremium\PremiumTarget;
use App\Services\CanonicalPricing\ForwardPremium\PremiumVatBasis;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimate;
use App\Services\CanonicalPricing\MarketReset\Enums\ResetEstimateBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimate;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\SupplierAdjustedEstimateBasis;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Support\EnergyRulesFixture as F;
use Tests\TestCase;
use Tests\Unit\CanonicalPricing\Support\HoldFlatCanonicalCalculator;

class PremiumPublicPrivacyTest extends TestCase
{
    private const PRIVATE_TEXT = 'private-note;observation=private-observation-731;publication=private-publication-842';

    private function premium(PremiumFamily $family, PremiumVatBasis $vat, bool $proxy, PremiumSource $source = PremiumSource::Market): PremiumEstimate
    {
        $date = fn (string $value) => CarbonImmutable::parse($value, 'Europe/Helsinki');
        $compatibility = new PremiumCompatibility($family, MeteringType::General, $vat, [ComponentType::EnergyGeneral], $family->isReset() ? 'monthly' : null);
        $row = new PremiumObservation(
            'private-lineage-token', 'Private Donor Name', $compatibility, ['energy_general' => 2.0],
            'private-energy-signature', $date('2025-12-10'), $date('2025-11-28'),
            $date('2025-12-01'), $date('2025-12-31'), $date('2025-12-01'), $date('2025-12-31'),
            self::PRIVATE_TEXT, pricingDate: $date('2025-12-01'), referencePeriodProxy: $proxy,
        );
        $duplicate = new PremiumObservation(...array_replace(get_object_vars($row), ['provenance' => self::PRIVATE_TEXT.';second-private-note']));
        $target = new PremiumTarget('target', $source === PremiumSource::OwnLineage ? $row->lineageId : 'target-lineage',
            $source === PremiumSource::Market ? 'Target Company' : $row->companyName, $compatibility, $date('2026-01-01'));
        $premium = (new ForwardPremiumResolver)->resolve($target, [$duplicate, $row]);
        $this->assertNotNull($premium);
        $this->assertSame($source, $premium->source);
        $this->assertSame(['energy_general' => 2.0], $premium->premiumsByBucket);
        $this->assertSame(2, $premium->observationCount);
        $this->assertSame(1, $premium->independentVariantCount);
        $this->assertSame(['private-lineage-token'], $premium->sourceLineages);
        $this->assertSame(['Private Donor Name'], $premium->sourceCompanies);
        $this->assertContains($row, $premium->observations);
        $this->assertSame(self::PRIVATE_TEXT, $row->provenance);
        $this->assertSame('private-energy-signature', $row->energyOfferSignature);

        return $premium;
    }

    private function assertPrivateDataAbsent(array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['private-', 'Private Donor Name', 'source_companies', 'source_lineages'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_public_premium_keeps_model_facts_without_private_evidence_for_each_typed_basis(): void
    {
        foreach (PremiumFamily::cases() as $family) {
            foreach (PremiumVatBasis::cases() as $vat) {
                foreach ([false, true] as $proxy) {
                    foreach (PremiumSource::cases() as $source) {
                        $premium = $this->premium($family, $vat, $proxy, $source);
                        $payload = $premium->toArray();
                        $this->assertPrivateDataAbsent($payload);
                        $this->assertSame($source->value, $payload['source']);
                        $this->assertSame($premium->premiumsByBucket, $payload['premiums_by_bucket']);
                        $this->assertSame(1, $payload['lineage_count']);
                        $this->assertSame(1, $payload['company_count']);
                        $this->assertSame(2, $payload['observation_count']);
                        $this->assertSame(1, $payload['independent_variant_count']);
                        $this->assertSame('lower', $payload['confidence']);
                        $this->assertSame($premium->flags, $payload['flags']);
                        $this->assertSame(['2025-11-28'], $payload['reference_trade_dates']);
                        $this->assertSame('2025-12-10', $payload['evidence_from']);
                        $this->assertSame('2025-12-10', $payload['evidence_through']);
                        $this->assertSame([
                            'pricing_date' => '2025-12-01', 'reference_period_proxy' => $proxy,
                            'delivery_start' => '2025-12-01', 'delivery_end' => '2025-12-31',
                            'provenance' => $family->value.($proxy ? ';reference_period_proxy' : ';matched_reference_period')
                                .';target_audience_vat_normalized_unknown_assumed;vat_basis='.$vat->value,
                        ], $payload['references'][0]);
                        $withPrivateFlag = new PremiumEstimate($source, $premium->premiumsByBucket, $premium->observations, 1, [...$premium->flags, self::PRIVATE_TEXT]);
                        $this->assertPrivateDataAbsent($withPrivateFlag->toArray());
                        $this->assertSame($premium->flags, $withPrivateFlag->toArray()['flags']);
                        $this->assertContains(self::PRIVATE_TEXT, $withPrivateFlag->flags);
                    }
                }
            }
        }
    }

    private function invalidPublicPremiums(array $valid): iterable
    {
        foreach (['source_companies', 'source_lineages', 'observation_id', 'publication_id', 'source_quote', 'unknown'] as $key) {
            yield $key => $valid + [$key => 'private-source-value'];
            $bad = $valid;
            $bad['references'][0][$key] = 'private-source-value';
            yield 'reference '.$key => $bad;
        }
        foreach (['private source quote', $valid['references'][0]['provenance'].';observation=731',
            str_replace('included', 'unknown', $valid['references'][0]['provenance']),
            str_replace('reference_period_proxy;', 'matched_reference_period;', $valid['references'][0]['provenance'])] as $provenance) {
            $bad = $valid;
            $bad['references'][0]['provenance'] = $provenance;
            yield 'provenance '.$provenance => $bad;
        }
        foreach (['references' => [[], ['private' => $valid['references'][0]], ['private source quote']],
            'flags' => [['private source quote'], ['private' => 'single_source_company']]] as $key => $values) {
            foreach ($values as $index => $value) {
                yield $key.$index => array_replace($valid, [$key => $value]);
            }
        }
        foreach (['pricing_date' => 'private source quote', 'delivery_start' => null, 'delivery_end' => '2026-02-30',
            'reference_period_proxy' => 1, 'provenance' => ['private source quote']] as $key => $value) {
            $bad = $valid;
            $bad['references'][0][$key] = $value;
            yield 'invalid '.$key => $bad;
        }
        foreach (PremiumEstimate::PUBLIC_KEYS as $key) {
            $bad = $valid;
            unset($bad[$key]);
            yield 'missing '.$key => $bad;
        }
        foreach (PremiumEstimate::PUBLIC_REFERENCE_KEYS as $key) {
            $bad = $valid;
            unset($bad['references'][0][$key]);
            yield 'missing reference '.$key => $bad;
        }
    }

    public function test_supplier_reset_and_nested_v5_normal_public_transport_do_not_publish_private_evidence(): void
    {
        Http::preventStrayRequests();
        foreach ([PremiumFamily::SupplierAdjusted, PremiumFamily::MarketReset] as $family) {
            $premium = $this->premium($family, PremiumVatBasis::Included, true);
            $offsets = array_fill_keys(array_map(fn ($m) => sprintf('2026-%02d', $m), range(2, 12)), 1.0);
            $estimate = $family->isReset()
                ? new ResetEstimate(ResetEstimateBasis::ForwardPremium, $offsets, 1, 'monthly', 9, curveTradeDate: '2025-12-31', tailStartsMonthKey: '2026-02', premium: $premium)
                : new SupplierAdjustedEstimate(SupplierAdjustedEstimateBasis::ForwardPremium, $offsets, 1, 9, 0, null, null, null, '2025-12-31', null, '2026-02', PriceEpisodeAnchor::missing(), premium: $premium);
            $this->assertPrivateDataAbsent($estimate->toArray());
            $this->assertSame($premium->toArray(), $estimate->toArray()['premium']);
            [, $output] = F::example('fixed_price', 9, 5);
            $data = (new CanonicalPricingParser)->parse($output['pricing'], $output['calculation'], $output['source_consistency'], withEnergyRules: true);
            $result = HoldFlatCanonicalCalculator::make()->calculate($data,
                new ContractContext('FixedPrice', 'OpenEnded', 'General', null, 'Household'),
                new EnergyUsage(total: 1200, basicLiving: 1200), new SpotAssumptions(null, null),
                CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki'),
                normalEnergyProjection: new NormalEnergyProjection(['energy_general' => 9.0], $estimate));
            $payload = $result->toCalculatedCostArray();
            $this->assertPrivateDataAbsent($payload);
            $this->assertSame($premium->toArray(), $payload['energy_rule_comparison']['projection']['estimate']['premium']);
            $this->assertEqualsWithDelta(119, $result->baseTotalCost, .00001);
            $this->assertSame($payload, ContractPricingViewData::fromArray($payload)->toArray());
            $this->assertSame($premium, $estimate->premium);

            $topKey = $family->isReset() ? 'reset_estimate' : 'supplier_adjusted_estimate';
            $payload[$topKey] = $payload['energy_rule_comparison']['projection']['estimate'];
            $this->assertSame($payload, ContractPricingViewData::fromArray($payload)->toArray());
            foreach ([$topKey.'.premium', 'energy_rule_comparison.projection.estimate.premium'] as $path) {
                foreach (PremiumFamily::cases() as $publicFamily) {
                    foreach (PremiumVatBasis::cases() as $vat) {
                        foreach ([false, true] as $proxy) {
                            $valid = $payload;
                            $public = $this->premium($publicFamily, $vat, $proxy)->toArray();
                            $public['references'][0]['pricing_date'] = null;
                            $public['flags'] = PremiumEstimate::PUBLIC_FLAGS;
                            data_set($valid, $path, $public);
                            $this->assertSame($valid, ContractPricingViewData::fromArray($valid)->toArray());
                        }
                    }
                }
                foreach ($this->invalidPublicPremiums($premium->toArray()) as $name => $invalid) {
                    $bad = $payload;
                    data_set($bad, $path, $invalid);
                    try {
                        ContractPricingViewData::fromArray($bad)->toArray();
                        $this->fail('Private or malformed premium accepted: '.$path.' '.$name);
                    } catch (InvalidArgumentException $exception) {
                        $this->assertStringContainsString('.premium', $exception->getMessage());
                    }
                    // A changed basis cannot bypass validation of a supplied premium.
                    data_set($bad, substr($path, 0, -strlen('premium')).'basis', 'hold_current_price');
                    try {
                        ContractPricingViewData::fromArray($bad)->toArray();
                        $this->fail('Premium validation bypassed by basis: '.$path.' '.$name);
                    } catch (InvalidArgumentException $exception) {
                        $this->assertStringContainsString('.premium', $exception->getMessage());
                    }
                }
            }
        }
    }
}
