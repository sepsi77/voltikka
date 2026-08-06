<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted;

use App\Services\ContractPricing\PricingFact;

/**
 * Finnish public copy for an adjustable open-ended annual estimate.
 *
 * Every sentence comes only from the validated `supplier_adjusted_estimate` payload.
 * Seller text and interpretation summaries are never public copy inputs.
 */
class SupplierAdjustedEstimateCopy
{
    public static function popoverBody(?PricingFact $estimate): ?string
    {
        if ($estimate === null) {
            return null;
        }

        $current = self::price($estimate->number('current_energy_price'));
        $annual = self::price($estimate->number('annual_equivalent_energy_price'));

        $body = $current !== null
            ? 'Nykyinen energianhinta '.$current.' c/kWh on myyjän julkaisema hinta.'
            : 'Nykyinen energianhinta on myyjän julkaisema hinta.';
        $body .= $annual !== null
            ? ' 12 kuukauden vastaava keskihinta '.$annual.' c/kWh on Voltikan arvio, joka perustuu '.self::basisPhrase($estimate).'.'
            : ' 12 kuukauden vastaava hinta on Voltikan arvio, joka perustuu '.self::basisPhrase($estimate).'.';

        return $body.' Toistaiseksi voimassa olevan sopimuksen myyjä voi muuttaa hintaa ilmoittamalla siitä etukäteen.'
            .' Tulevia hintoja tai muutosaikataulua ei tiedetä. Arvio ei ole hintalupaus.';
    }

    /**
     * One quiet note under the contract-detail receipt. It adds only the unknown future
     * and the estimate basis; the receipt already shows both prices.
     */
    public static function receiptNote(?PricingFact $estimate): ?string
    {
        if ($estimate === null) {
            return null;
        }

        return 'Tulevia energiahintoja tai niiden muutosaikataulua ei tiedetä. Arvion myöhemmät kuukaudet perustuvat '
            .self::basisPhrase($estimate).', ja perusmaksu on pidetty nykyisellään.';
    }

    private static function basisPhrase(PricingFact $estimate): string
    {
        return match ($estimate->string('basis')) {
            'forward_curve_shift' => 'nykyiseen julkaistuun hintaan ja tukkumarkkinan ennakkohintoihin eli sähköfutuureihin',
            'spot_seasonal_index' => 'nykyiseen julkaistuun hintaan ja pörssisähkön usean vuoden kausivaihteluun, koska tukkumarkkinan ennakkohintoja ei ollut saatavilla',
            default => 'nykyiseen julkaistuun hintaan, koska käyttökelpoista markkinatietoa ei ollut saatavilla',
        };
    }

    private static function price(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, ',', ' ') : null;
    }
}
