<?php

namespace App\Services\Analytics;

/**
 * The closed page-type, reason and goal lists of the "Löysitkö etsimäsi?" widget.
 *
 * One source for the Blade component (labels) and the handler (accepted codes), so a
 * stored code always had a visible label on that page. Codes are stored; labels are not,
 * so a label can be reworded without changing old rows. Remove a code only with a plan
 * for the rows that hold it. Paper page "Feedback · Löysitkö etsimäsi?" has the page map.
 */
final class PageFeedbackCatalog
{
    public const OTHER = 'other';

    /** Goal labels. One site-wide list so goals compare across pages. */
    private const GOALS = [
        'find_contract' => 'Etsin uutta sähkösopimusta',
        'compare_current' => 'Vertailen nykyistä sopimustani',
        'choose_type' => 'Selvitän, mikä sopimustyyppi sopii minulle',
        'check_spot' => 'Tarkistan pörssisähkön hinnan',
        'time_usage' => 'Ajoitan sähkönkäyttöä halvoille tunneille',
        'follow_prices' => 'Seuraan sähkön hintoja',
        'estimate_consumption' => 'Arvioin sähkönkulutustani',
        'check_bill' => 'Selvitän, maksanko sähköstä liikaa',
        'solar' => 'Harkitsen aurinkopaneeleja',
        'heat_pump' => 'Harkitsen lämpöpumppua',
        'company' => 'Tutustun sähköyhtiöön',
        'research' => 'Etsin tietoa työtä tai opiskelua varten',
        self::OTHER => 'Jokin muu',
    ];

    /** Reason labels, per reason set. "Jokin muu" is added last to every set. */
    private const REASON_SETS = [
        'home' => [
            'info_not_found' => 'En löytänyt etsimääni tietoa',
            'wrong_or_old_data' => 'Tieto näytti väärältä tai vanhalta',
            'hard_to_use' => 'Sivua oli vaikea käyttää',
        ],
        'listing' => [
            'no_suitable_contract' => 'En löytänyt sopivaa sopimusta',
            'hard_to_compare' => 'Hintoja oli vaikea vertailla',
            'missing_filter' => 'Tarvitsemani rajaus puuttui',
            'wrong_or_old_data' => 'Tieto näytti väärältä tai vanhalta',
            'hard_to_use' => 'Sivua oli vaikea käyttää',
        ],
        'contract_detail' => [
            'unclear_price_or_terms' => 'Hinta tai ehdot jäivät epäselviksi',
            'wrong_or_old_data' => 'Tieto näytti väärältä tai vanhalta',
            'how_to_order' => 'En löytänyt, miten sopimuksen voi tilata',
            'want_to_compare' => 'Halusin vertailla tätä muihin',
            'hard_to_use' => 'Sivua oli vaikea käyttää',
        ],
        'company' => [
            'missing_contracts' => 'Yhtiön sopimuksia puuttui',
            'unclear_comparison' => 'Hintavertailu oli epäselvä',
            'contact_info' => 'Etsin yhteystietoja tai asiakaspalvelua',
            'wrong_or_old_data' => 'Tieto näytti väärältä tai vanhalta',
            'hard_to_use' => 'Sivua oli vaikea käyttää',
        ],
        'spot_price' => [
            'price_missing_or_wrong' => 'Hinta puuttui tai näytti väärältä',
            'chart_hard_to_read' => 'Kaaviota oli vaikea lukea',
            'need_other_range' => 'Tarvitsin eri aikavälin tai tarkkuuden',
            'forecast_missing' => 'Ennuste puuttui tai oli liian lyhyt',
            'hard_to_use' => 'Sivua oli vaikea käyttää',
        ],
        'price_data' => [
            'chart_hard_to_understand' => 'Kaaviota oli vaikea ymmärtää',
            'need_other_range_or_type' => 'Tarvitsin eri aikavälin tai sopimustyypin',
            'forecast_unreliable' => 'Ennuste tuntui epäluotettavalta',
            'wrong_or_old_data' => 'Tieto näytti väärältä tai vanhalta',
            'hard_to_use' => 'Sivua oli vaikea käyttää',
        ],
        'consumption_calculator' => [
            'home_or_heating_missing' => 'Kotini tai lämmitystapani puuttui',
            'result_seems_wrong' => 'Tulos tuntui väärältä',
            'result_unclear' => 'En ymmärtänyt tulosta',
            'hard_to_use' => 'Laskuria oli vaikea käyttää',
        ],
        'bill_comparison' => [
            'bill_info_not_found' => 'En löytänyt laskusta tarvittavia tietoja',
            'result_seems_wrong' => 'Tulos tuntui väärältä',
            'result_unclear' => 'En ymmärtänyt tulosta',
            'hard_to_use' => 'Laskuria oli vaikea käyttää',
        ],
        'solar_calculator' => [
            'address_not_found' => 'Osoitettani ei löytynyt',
            'production_seems_wrong' => 'Tuottoarvio tuntui väärältä',
            'savings_seem_wrong' => 'Säästöarvio tuntui väärältä',
            'want_price_or_payback' => 'Halusin hinnan tai takaisinmaksuajan',
            'hard_to_use' => 'Laskuria oli vaikea käyttää',
        ],
        'heat_pump_calculator' => [
            'type_missing' => 'Lämpöpumppu- tai talotyyppi puuttui',
            'savings_seem_wrong' => 'Säästöarvio tuntui väärältä',
            'want_price_or_payback' => 'Halusin hinnan tai takaisinmaksuajan',
            'hard_to_use' => 'Laskuria oli vaikea käyttää',
        ],
        'reading' => [
            'did_not_answer' => 'Teksti ei vastannut kysymykseeni',
            'hard_to_understand' => 'Tietoa oli vaikea ymmärtää',
            'old_data' => 'Tieto näytti vanhalta',
            'hard_to_use' => 'Sivua oli vaikea käyttää',
        ],
        'locations' => [
            'location_missing' => 'Paikkakuntaani ei löytynyt',
            'wrong_or_old_data' => 'Tieto näytti väärältä tai vanhalta',
            'hard_to_use' => 'Sivua oli vaikea käyttää',
        ],
    ];

    /**
     * Page type => reason set (null: no vote and no reasons, the goal step shows first)
     * and the five goals shown on that page.
     */
    private const PAGE_TYPES = [
        'home' => ['reasons' => 'home', 'goals' => ['find_contract', 'check_spot', 'compare_current', 'follow_prices', 'research']],
        'listing' => ['reasons' => 'listing', 'goals' => ['find_contract', 'compare_current', 'choose_type', 'follow_prices', 'research']],
        'contract_detail' => ['reasons' => 'contract_detail', 'goals' => ['find_contract', 'compare_current', 'choose_type', 'company', 'research']],
        'company_detail' => ['reasons' => 'company', 'goals' => ['company', 'find_contract', 'compare_current', 'follow_prices', 'research']],
        'company_list' => ['reasons' => 'company', 'goals' => ['company', 'find_contract', 'compare_current', 'follow_prices', 'research']],
        'spot_price' => ['reasons' => 'spot_price', 'goals' => ['check_spot', 'time_usage', 'follow_prices', 'find_contract', 'research']],
        'statistics' => ['reasons' => 'price_data', 'goals' => ['follow_prices', 'choose_type', 'find_contract', 'check_spot', 'research']],
        'forecast' => ['reasons' => 'price_data', 'goals' => ['follow_prices', 'choose_type', 'find_contract', 'check_spot', 'research']],
        'consumption_calculator' => ['reasons' => 'consumption_calculator', 'goals' => ['estimate_consumption', 'find_contract', 'check_bill', 'heat_pump', 'solar']],
        'bill_comparison' => ['reasons' => 'bill_comparison', 'goals' => ['check_bill', 'compare_current', 'find_contract', 'estimate_consumption', 'research']],
        'solar_calculator' => ['reasons' => 'solar_calculator', 'goals' => ['solar', 'estimate_consumption', 'heat_pump', 'follow_prices', 'research']],
        'heat_pump_calculator' => ['reasons' => 'heat_pump_calculator', 'goals' => ['heat_pump', 'estimate_consumption', 'solar', 'follow_prices', 'research']],
        'article' => ['reasons' => 'reading', 'goals' => ['choose_type', 'find_contract', 'follow_prices', 'check_spot', 'research']],
        'about' => ['reasons' => 'reading', 'goals' => ['find_contract', 'choose_type', 'follow_prices', 'compare_current', 'research']],
        'locations' => ['reasons' => 'locations', 'goals' => ['find_contract', 'compare_current', 'choose_type', 'follow_prices', 'research']],
        'not_found' => ['reasons' => null, 'goals' => ['find_contract', 'check_spot', 'compare_current', 'follow_prices', 'research']],
    ];

    /** @return list<string> */
    public static function pageTypes(): array
    {
        return array_keys(self::PAGE_TYPES);
    }

    public static function isPageType(string $pageType): bool
    {
        return array_key_exists($pageType, self::PAGE_TYPES);
    }

    /** A page without a vote opens directly at the goal step ("Mitä etsit?" on 404). */
    public static function hasVote(string $pageType): bool
    {
        return self::PAGE_TYPES[$pageType]['reasons'] !== null;
    }

    /**
     * Code => label, "Jokin muu" last. Empty for a page type without a vote.
     *
     * @return array<string, string>
     */
    public static function reasonsFor(string $pageType): array
    {
        $set = self::PAGE_TYPES[$pageType]['reasons'] ?? null;

        if ($set === null) {
            return [];
        }

        return self::REASON_SETS[$set] + [self::OTHER => 'Jokin muu'];
    }

    /**
     * Code => label, "Jokin muu" last.
     *
     * @return array<string, string>
     */
    public static function goalsFor(string $pageType): array
    {
        $goals = [];

        foreach (self::PAGE_TYPES[$pageType]['goals'] ?? [] as $code) {
            $goals[$code] = self::GOALS[$code];
        }

        return $goals + [self::OTHER => self::GOALS[self::OTHER]];
    }

    /** @return array<string, string> Every reason code => one label, for the admin panel. */
    public static function allReasonLabels(): array
    {
        $labels = [];

        foreach (self::REASON_SETS as $reasons) {
            $labels += $reasons;
        }

        return $labels + [self::OTHER => 'Jokin muu'];
    }

    /** @return array<string, string> */
    public static function allGoalLabels(): array
    {
        return self::GOALS;
    }
}
