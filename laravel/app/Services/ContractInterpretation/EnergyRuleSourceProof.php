<?php

namespace App\Services\ContractInterpretation;

use App\Services\CanonicalPricing\DTO\EnergyPriceRule;
use App\Services\CanonicalPricing\Enums\EnergyPriceRuleKind;
use App\Services\CanonicalPricing\Exceptions\CanonicalPricingParseException;

/** Complete clause proof with bounded non-pricing context, not general language inference. */
final class EnergyRuleSourceProof
{
    private const TEXT_FIELDS = ['contract_name', 'pricing_name', 'short_description', 'long_description', 'extra_information_fi', 'extra_information_default'];

    private const SCOPES = [
        'General' => 'energy_general', 'Day' => 'energy_day', 'Night' => 'energy_night',
        'Winter' => 'energy_seasonal_winter', 'Other-season' => 'energy_seasonal_other',
        'Yleissähkön' => 'energy_general', 'Päiväsähkön' => 'energy_day', 'Yösähkön' => 'energy_night',
        'Talvisähkön' => 'energy_seasonal_winter', 'Muun ajan sähkön' => 'energy_seasonal_other',
    ];

    public function validate(array $output, array $input, ContractInterpretationValidator $validator): array
    {
        $errors = [];
        $clauses = $this->sourceClauses($input);
        foreach ($output['pricing']['phases'] ?? [] as $phaseIndex => $phase) {
            foreach ($phase['components'] ?? [] as $index => $component) {
                $path = "$.pricing.phases[{$phaseIndex}].components[{$index}].energy_rule";
                $energy = in_array($component['component_type'] ?? null, array_values(self::SCOPES), true)
                    && ($component['unit'] ?? null) === 'cents_per_kwh';
                $raw = $component['energy_rule'] ?? null;
                if (! $energy) {
                    if ($raw !== null) {
                        $errors[] = "{$path} must be null for non-energy components.";
                    }

                    continue;
                }
                if (! is_array($raw)) {
                    $errors[] = "{$path} requires an object, including for unknown energy terms.";

                    continue;
                }
                try {
                    $rule = EnergyPriceRule::fromArray($raw);
                } catch (CanonicalPricingParseException $exception) {
                    $errors[] = "{$path}: ".$exception->getMessage();

                    continue;
                }
                if ($rule->kind === EnergyPriceRuleKind::Unknown) {
                    continue;
                }
                if (! $this->finite($component['amount'] ?? null)
                    || ! $this->componentScope($component, $raw['evidence'], $input, $validator)
                    || (! $this->matches($component, $raw, $clauses, false)
                        && (! $this->normalContinuation($phase, $component, $raw, $input, $validator)
                            || ! $this->matches($component, $raw, $clauses, false, true)))) {
                    $errors[] = "{$path} lacks complete source-clause and component proof.";
                }
                $normal = $raw['normal_basis'];
                if ($normal !== null && $normal['kind'] !== 'unknown') {
                    if (! $this->finite($component['normal_amount'] ?? null)
                        || ! $this->componentScope($component, $normal['evidence'], $input, $validator)
                        || ! $this->matches($component, $normal, $clauses, true)) {
                        $errors[] = "{$path}.normal_basis lacks independent normal-tariff proof.";
                    }
                }
                if ($rule->kind->isDiscount()) {
                    $normalAmount = $component['normal_amount'] ?? null;
                    if (! $this->finite($normalAmount) || $normalAmount < 0) {
                        $errors[] = "{$path} requires a non-negative normal amount.";

                        continue;
                    }
                    $expected = $rule->kind === EnergyPriceRuleKind::PercentageDiscount
                        ? $normalAmount * (1 - $rule->discountValue / 100)
                        : $normalAmount - $rule->discountValue;
                    if ($rule->floorAmount !== null) {
                        $expected = max($expected, $rule->floorAmount);
                    }
                    if (! $this->finite($component['amount'] ?? null) || abs($expected - $component['amount']) > 0.000001) {
                        $errors[] = "{$path} formula does not equal the billed source amount.";
                    }
                }
            }
        }

        return $errors;
    }

    private function componentScope(array $component, array $evidence, array $input, ContractInterpretationValidator $validator): bool
    {
        $sources = array_column($evidence, 'source');
        $matches = 0;
        $candidates = 0;
        foreach ($input['components'] ?? [] as $index => $source) {
            if ($validator->canonicalComponentTypeForSource($source['price_component_type'] ?? null, $input['pricing_model'] ?? null) !== $component['component_type']) {
                continue;
            }
            $candidates++;
            if (! in_array($source['payment_unit'] ?? null, ['c/kWh', 'cents_per_kwh', 'CentPerKiwattHour'], true)) {
                continue;
            }
            if (in_array("components[{$index}].price_component_type", $sources, true)
                && in_array("components[{$index}].payment_unit", $sources, true)) {
                $matches++;
            }
        }

        return $matches === 1 && $candidates === 1;
    }

    private function matches(array $component, array $rule, ?array $clauses, bool $normal, bool $normalContinuation = false): bool
    {
        if ($clauses === null) {
            return false;
        }
        $candidates = array_values(array_filter($clauses, fn (array $clause): bool => $clause['component_type'] === $component['component_type'] && $clause['normal'] === ($normal || $normalContinuation)));
        if (! $this->compatiblePeriods($candidates)) {
            return false;
        }
        foreach ($candidates as $clause) {
            if ($clause['kind'] !== $rule['kind'] || $clause['starts'] !== $rule['starts'] || $clause['ends'] !== $rule['ends']) {
                continue;
            }
            if ($clause['kind'] === 'absolute_discount' || $clause['kind'] === 'percentage_discount') {
                if (! $this->sameNumber($clause['discount_value'], $rule['discount_value']) || ! $this->sameNumber($clause['floor_amount'], $rule['floor_amount'])) {
                    continue;
                }
            } elseif (! $this->sameNumber($clause['amount'], $component[$normal ? 'normal_amount' : 'amount'] ?? null)) {
                continue;
            }
            if (array_diff($clause['required_sources'] ?? [], array_column($rule['evidence'], 'source')) !== []
                || array_filter($clause['required_evidence'] ?? [], fn (array $citation): bool => ! in_array($citation, $rule['evidence'], true)) !== []) {
                continue;
            }
            foreach ($rule['evidence'] as $citation) {
                if ($citation['source'] === $clause['source']
                    && in_array($citation['quote'], [$clause['quote'], rtrim($clause['quote'], '.;!')], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalContinuation(array $phase, array $component, array $rule, array $input, ContractInterpretationValidator $validator): bool
    {
        if (($phase['phase_kind'] ?? null) !== 'normal' || ($component['price_role'] ?? null) !== 'normal'
            || $rule['kind'] !== 'adjustable_tariff') {
            return false;
        }
        $citations = array_column([...($component['evidence'] ?? []), ...$rule['evidence']], 'source');
        foreach ($input['components'] ?? [] as $index => $source) {
            if ($validator->canonicalComponentTypeForSource($source['price_component_type'] ?? null, $input['pricing_model'] ?? null) !== $component['component_type']
                || ($source['has_discount'] ?? false) !== true || ! $this->sameNumber($component['amount'], $source['price'] ?? null)) {
                continue;
            }
            $type = $source['discount_type'] ?? null;
            if ($type === 'NFirstMonth') {
                $months = $source['discount_n_first_months'] ?? null;
                if (! is_numeric($months) || $months <= 0 || (float) (int) $months !== (float) $months) {
                    continue;
                }
                $start = ['kind' => 'after_months', 'value' => (string) (int) $months];
                $limit = 'discount_n_first_months';
            } elseif ($type === 'UntilDate') {
                $rawDate = substr((string) ($source['discount_until_date'] ?? ''), 0, 10);
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
                if ($date === false || $date->format('Y-m-d') !== $rawDate) {
                    continue;
                }
                $start = ['kind' => 'date', 'value' => $date->modify('+1 day')->format('Y-m-d')];
                $limit = 'discount_until_date';
            } else {
                continue;
            }
            $required = array_map(fn (string $field): string => "components[{$index}].{$field}", ['price', 'has_discount', 'discount_type', $limit]);
            if (($phase['starts'] ?? null) === $start && array_diff($required, $citations) === []) {
                return true;
            }
        }

        return false;
    }

    private function compatiblePeriods(array $clauses): bool
    {
        foreach ($clauses as $index => $clause) {
            foreach (array_slice($clauses, $index + 1) as $other) {
                $fields = array_flip(['kind', 'amount', 'discount_value', 'floor_amount']);
                if (array_intersect_key($clause, $fields) === array_intersect_key($other, $fields)) {
                    continue;
                }
                [$domain, $start, $end] = $this->factWindow($clause);
                [$otherDomain, $otherStart, $otherEnd] = $this->factWindow($other);
                if ($domain !== $otherDomain) {
                    return false; // No inferred signup date to compare relative and absolute periods.
                }
                $disjoint = $domain === 'months' ? $end <= $otherStart || $otherEnd <= $start
                    : $end < $otherStart || $otherEnd < $start;
                if (! $disjoint) {
                    return false;
                }
            }
        }

        return true;
    }

    private function factWindow(array $clause): array
    {
        if ($clause['kind'] === 'adjustable_tariff') {
            // "Currently" observes one amount on one date; it is not a future price lock.
            return ['dates', $clause['observation_date'], $clause['observation_date']];
        }
        if ($clause['starts']['kind'] === 'date') {
            return ['dates', $clause['starts']['value'], $clause['ends']['value']];
        }

        return ['months', (int) ($clause['starts']['value'] ?? 0), (int) $clause['ends']['value']];
    }

    private function sourceClauses(array $input): ?array
    {
        $clauses = [];
        foreach (self::TEXT_FIELDS as $field) {
            $text = $input[$field] ?? null;
            if ($text === null || $text === '') {
                continue;
            }
            if (! is_string($text) || mb_strlen($text) > 12000) {
                return null;
            }
            // Only sentence/semicolon boundaries. Never extract a promising substring
            // from a conditional sentence, or split a decimal, email address or ISO date.
            $parts = preg_split('/(?<=;)\\s*|(?<!puh\\.)(?<!tel\\.)(?<!klo\\.)(?<!mm\\.)(?<=[.!?])\\s+(?!päivään\\b)/iu', $text);
            $consumed = null;
            foreach ($parts as $partIndex => $part) {
                if ($partIndex === $consumed) {
                    continue;
                }
                $quote = trim($part);
                if ($quote === '') {
                    continue;
                }
                $body = rtrim($quote, '.;!');
                if ($this->sourceContext($body, $input, $field, $parts, $partIndex)) {
                    continue;
                }
                $clause = $this->linkedDatedLock($body, trim($parts[$partIndex + 1] ?? ''), $input, $field);
                if ($clause !== null) {
                    $consumed = $partIndex + 1;
                }
                $clause ??= $this->linkedTerm($body, $input)
                    ?? $this->linkedCurrentTariff($body, $input)
                    ?? $this->clause($this->combinedEnergy($body, $input), $input['analysis_date'] ?? '', $this->hasGeneralScope($input));
                if ($clause === null) {
                    if ($this->isNonPricing($body, in_array($field, ['contract_name', 'pricing_name'], true))) {
                        continue;
                    }

                    return null;
                }
                try {
                    EnergyPriceRule::bounds($clause['starts'], $clause['ends'], $clause['kind'] !== 'adjustable_tariff');
                } catch (CanonicalPricingParseException) {
                    return null;
                }
                $clauses[] = $clause + ['source' => $field, 'quote' => $quote];
            }
        }

        return $clauses;
    }

    /** A shared duration applies to both explicitly fixed energy and the fee, not vice versa. */
    private function combinedEnergy(string $text, array $input): string
    {
        $name = preg_quote((string) ($input['contract_name'] ?? ''), '~');
        if ($name !== '' && $this->hasGeneralScope($input)
            && preg_match('~^'.$name.' -sopimuksessa on kiinteä energiahinta (?<energy>[0-9]+(?:[.,][0-9]+)?) snt/kWh \\+ perusmaksu (?<fee>[0-9]+(?:[.,][0-9]+)?) €/kk ensimmäisen kuukauden ajan sopimuksen aloituspäivästä(?: eteenpäin)?$~uD', $text, $match)) {
            return 'Energiahinta on kiinteä '.$match['energy'].' snt/kWh ensimmäisen 1 kuukauden ajan';
        }

        return $text;
    }

    /** A dated lock needs an explicit start in the adjacent, single-scope price statement. */
    private function linkedDatedLock(string $text, string $next, array $input, string $field): ?array
    {
        if (! $this->hasGeneralScope($input)
            || ! preg_match('~^Energiahinta on ([0-9]+(?:[.,][0-9]+)?) snt/kWh ([0-9]{1,2}\\.[0-9]{1,2}\\.[0-9]{4}) alkaen$~uD', $text, $price)
            || ! preg_match('/^Hinta on lukittu ([0-9]{1,2}\\.[0-9]{1,2}\\.[0-9]{4}) asti[.]?$/uD', $next, $lock)) {
            return null;
        }
        $dates = [];
        foreach ([$price[2], $lock[1]] as $raw) {
            $date = \DateTimeImmutable::createFromFormat('!j.n.Y', $raw);
            if ($date === false || $date->format('j.n.Y') !== $raw) {
                return null;
            }
            $dates[] = $date->format('Y-m-d');
        }
        $clause = $this->clause('General energy price is fixed at '.str_replace(',', '.', $price[1]).' cents/kWh from '.$dates[0].' through '.$dates[1], $input['analysis_date']);
        if ($clause !== null) {
            $clause['required_evidence'] = [['source' => $field, 'quote' => $next]];
        }

        return $clause;
    }

    /** Only an undiscounted current component can supply an unstated numeric tariff value. */
    private function linkedCurrentTariff(string $text, array $input): ?array
    {
        if (! $this->hasGeneralScope($input) || ! in_array($text, ['Energiahinta on muuttuva', 'Normaali energiahinta on muuttuva'], true)) {
            return null;
        }
        foreach ($input['components'] as $index => $source) {
            if (($source['price_component_type'] ?? null) !== 'General') {
                continue;
            }
            if (($source['has_discount'] ?? null) !== false || ! $this->finite($source['price'] ?? null)
                || ! in_array($source['payment_unit'] ?? null, ['c/kWh', 'cents_per_kwh', 'CentPerKiwattHour'], true)) {
                return null;
            }
            $normal = str_starts_with($text, 'Normaali') ? 'normal ' : '';
            $clause = $this->clause('General '.$normal.'energy tariff is adjustable and is currently '.$source['price'].' cents/kWh', $input['analysis_date']);
            if ($clause !== null) {
                $clause['required_sources'] = ["components[{$index}].price", "components[{$index}].has_discount"];
            }

            return $clause;
        }

        return null;
    }

    /** Whole-term prose supplies the guarantee; structured identity supplies only its rate. */
    private function linkedTerm(string $text, array $input): ?array
    {
        if (! $this->hasGeneralScope($input) || ($input['contract_type'] ?? null) !== 'FixedTerm'
            || ! preg_match('/^Fixed([1-9][0-9]{0,2})$/D', $input['fixed_time_range'] ?? '', $duration)
            || ! preg_match('/^(?:Hinta määräytyy ostohetken perusteella ja pysyy samana koko määräaikaisen sopimuskauden ajan|Hinta pysyy samana koko sopimuskauden ajan)$/uD', $text)) {
            return null;
        }
        foreach ($input['components'] as $index => $source) {
            if (($source['price_component_type'] ?? null) !== 'General') {
                continue;
            }
            if (($source['has_discount'] ?? null) !== false || ! $this->finite($source['price'] ?? null)
                || ! in_array($source['payment_unit'] ?? null, ['c/kWh', 'cents_per_kwh', 'CentPerKiwattHour'], true)) {
                return null;
            }
            $clause = $this->clause('General energy price is fixed at '.$source['price'].' cents/kWh for the first '.$duration[1].' months', $input['analysis_date']);
            if ($clause !== null) {
                $clause['required_sources'] = ['contract_type', 'fixed_time_range', "components[{$index}].price", "components[{$index}].has_discount"];
            }

            return $clause;
        }

        return null;
    }

    /** These contexts retain their scope. None supplies a normal quote or a guarantee start. */
    private function sourceContext(string $text, array $input, string $field, array $parts, int $index): bool
    {
        $name = (string) ($input['contract_name'] ?? '');
        if (in_array($field, ['contract_name', 'pricing_name'], true)
            && preg_match('/^([\\p{L}\\p{M} \'’&-]+) ([1-9][0-9]{0,2}) kk$/uD', $text, $match)
            && ($input['fixed_time_range'] ?? null) === 'Fixed'.$match[2]) {
            return $this->isNonPricing($match[1], true);
        }
        if ($text === 'Sopimus sitoo asiakasta ja myyjää koko '.substr($input['fixed_time_range'] ?? '', 5).' kk:n sopimusajan'
            && ($input['contract_type'] ?? null) === 'FixedTerm') {
            return true;
        }
        // Only this complete continuation chain may accompany a proven one-month intro.
        // Its lock has an end but no start: it cannot become a finite energy rule.
        if (! $this->hasGeneralScope($input) || $name === '') {
            return false;
        }
        $intro = null;
        foreach ($parts as $partIndex => $part) {
            $body = rtrim(trim($part), '.;!');
            if ($this->combinedEnergy($body, $input) !== $body) {
                if ($intro !== null) {
                    return false;
                }
                $intro = $partIndex;
            }
        }
        if ($intro === null) {
            return false;
        }
        if ($text === $name.' on toistaiseksi voimassa oleva sähkösopimus, jossa sähkön hinta on kilpailukykyinen ja markkinahintainen'
            || $text === $name.' TÄÄLTÄ Sopimuksen kesto: Toistaiseksi voimassaoleva') {
            return ($input['contract_type'] ?? null) === 'OpenEnded';
        }
        $continuation = 'Tämän jälkeen sopimuksen hinta noudattelee mm. markkinahintaa Nasdaq sähköjohdannaispörssissä, johon lisätään kiinteä kuukausittainen perusmaksu';
        $reset = 'Hinta tarkistetaan kvartaaleittain ja tulevan kvartaalin hinta ilmoitetaan verkkosivuillamme aina maalis- kesä-, syys- ja joulukuun 15. päivään mennessä';
        $bodies = array_map(fn (string $part): string => rtrim(trim($part), '.;!'), $parts);
        $start = array_search($continuation, $bodies, true);
        if ($start !== $intro + 1 || ! in_array($index, [$start, $start + 1, $start + 2, $start + 3], true)
            || ($bodies[$start + 3] ?? null) !== $reset
            || ! preg_match('~^Energianhinta ([0-9]+(?:[.,][0-9]+)?) snt/kWh \\+ perusmaksu ([0-9]+(?:[.,][0-9]+)?) ?€/kk$~uD', $bodies[$start + 1] ?? '')
            || ! preg_match('/^Hinta on lukittu ([0-9]{1,2}\\.[0-9]{1,2}\\.[0-9]{4}) asti$/uD', $bodies[$start + 2] ?? '', $lock)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!j.n.Y', $lock[1]);

        return $date !== false && $date->format('j.n.Y') === $lock[1];
    }

    private function hasGeneralScope(array $input): bool
    {
        if (($input['metering'] ?? null) !== 'General' || ($input['pricing_model'] ?? null) === 'Spot') {
            return false;
        }
        $types = [];
        foreach ($input['components'] ?? [] as $component) {
            $type = $component['price_component_type'] ?? null;
            if (in_array($type, ['General', 'DayTime', 'NightTime', 'SeasonalWinter', 'SeasonalWinterDay', 'SeasonalOther'], true)
                || in_array($component['payment_unit'] ?? null, ['c/kWh', 'cents_per_kwh', 'CentPerKiwattHour'], true)) {
                $types[] = $type;
            }
        }

        return $types === ['General'];
    }

    private function isNonPricing(string $text, bool $productName = false): bool
    {
        if (preg_match('/^(?:(?:Sähkö(?:mme)? on )?100 ?% uusiutuvaa(?: (?:sähköä|energiaa))?|100 ?% renewable energy|Sähkö(?:mme)? tuotetaan (?:tuuli|vesi|aurinko)voimalla)$/iuD', $text)) {
            return true;
        }
        if (preg_match('/^(?:Tervetuloa asiakkaaksemme|Kiitos luottamuksestasi|Welcome(?: to our service)?|Thank you for choosing us|Jos (?:tarvitset apua|sinulla on kysyttävää), ota yhteyttä asiakaspalvelu(?:un|umme)|If you need help, contact (?:our )?support)$/iuD', $text)) {
            return true;
        }
        if (preg_match('/^(?:Asiakaspalvelu(?:mme)?|Puhelinpalvelu(?:mme)?) (?:palvelee|on avoinna)(?: (?:arkisin|ma-pe|maanantaista perjantaihin))? (?:klo\\.? )?[0-2]?[0-9](?:[.:][0-5][0-9])?[-–][0-2]?[0-9](?:[.:][0-5][0-9])?$/iuD', $text)) {
            return true;
        }
        // A contact label must lead to a whole contact value, never arbitrary prose.
        if (preg_match('/^(?:Ota yhteyttä|Contact|Lisätietoja saat|For more information, contact|Sähköposti|Email)[:. ]+(?<email>[^\s]+)$/iuD', $text, $contact)
            && filter_var($contact['email'], FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }
        if (preg_match('/^(?:(?:Asiakaspalvelu|Puhelin|Puh|Contact|Telephone|Tel)[:. ]+)?[+0][0-9 ()-]{6,30}$/iuD', $text)
            && preg_match_all('/[0-9]/', $text) >= 7 && preg_match_all('/[0-9]/', $text) <= 15) {
            return true;
        }
        $screen = str_ireplace(['customer support', 'customer service'], 'support', $text);
        // These signals reject unsupported pricing AND detached qualifications such as
        // "Only for new customers". They never establish a positive pricing fact.
        if (preg_match('/(?:nord[ -]?pool|index|indeks|consumption[ -]?effect|kulutusvaikut|spot|pörssi|market[ -]?linked|hinta|hinn|tariff|alennu|maksu|veloit|kiinte|muuttu|takuu|tarjous|kampanja|sopimuseh|edellyt|riippu|vähimm|enimm|sovellet|voimassa|rajoit|asiakk|customer|maksa|payment|offer|promotion|withdraw|valid|expire|ehdo|poikkeu|price|discount|fixed|adjustable|guarantee|charge|fee|condition|eligible|qualif|depend|apply|applies|subject|change|reserve|right|pidät|oikeu|muuttaa|muutos|cents|snt|kwh|euro|€|%|\b(?:eur|rate|cost|normal|normaali|vain|only|unless|except|otherwise|provided|when|kun|jos|mikäli|if|not|ei|eikä|optional|valinnainen|ehto|ehdot|terms|this|these|that|tämä|nämä|se|ne|etu|edut|voi|may|can)\b)/iu', $screen)) {
            return false;
        }
        if ($productName) {
            return preg_match('/^[\p{L}\p{M} \'’&-]+$/uD', $text) === 1;
        }

        return preg_match('/^(?:(?:Luotettavaa|Vastuullista|Puhdasta|Uusiutuvaa|Helppoa) (?:sähköä|energiaa|palvelua)(?: kotiisi)?|(?:Reliable|Renewable|Clean|Sustainable) energy(?: for your home)?|(?:Asiakaspalvelu(?:mme)?|Tukipalvelu(?:mme)?) (?:auttaa|palvelee|on avoinna)(?: arkisin)?|Contact (?:our )?support(?: team)?|(?:Tilaa|Tee)(?: (?:sähkö)?sopimus)? (?:helposti )?verkossa)$/iuD', $text) === 1;
    }

    private function clause(string $text, string $analysisDate, bool $generalScope = false): ?array
    {
        if ($generalScope && preg_match('/^(?:Energiahinta|Energian hinta|Sähkön hinta|Normaali energiahinta|Normaali energiatariffi|Kiinteä energiahinta)\\b/iu', $text)) {
            $text = 'Yleissähkön '.mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
        }
        // Bounded synonymous positive forms; no qualifier or negation is removed.
        $text = str_replace(['energian hinta', 'sähkön hinta', 'kiinteä energiahinta on'], ['energiahinta', 'energiahinta', 'energiahinta on kiinteä'], $text);
        $number = '(?<number>[0-9]+(?:[.,][0-9]+)?)';
        $scope = '(?<scope>'.implode('|', array_map(fn (string $scope): string => preg_quote($scope, '~'), array_keys(self::SCOPES))).')';
        $period = '(?:for the first (?<months>[1-9][0-9]{0,2}) months|ensimmäiset (?<monthsFi>[1-9][0-9]{0,2}) kuukautta|from (?<from>[0-9]{4}-[0-9]{2}-[0-9]{2}) through (?<to>[0-9]{4}-[0-9]{2}-[0-9]{2})|ajalla (?<fromFi>[0-9]{4}-[0-9]{2}-[0-9]{2}) – (?<toFi>[0-9]{4}-[0-9]{2}-[0-9]{2}))';
        $patterns = [
            'fixed_price' => $scope.' (?:(?<normal>normal )?energy price is fixed at '.$number.' cents/kWh '.$period.'|(?<normalFi>normaali )?energiahinta on kiinteä (?<numberFi>[0-9]+(?:[.,][0-9]+)?) snt/kWh '.$this->finnishPeriod().')',
            'adjustable_tariff' => $scope.' (?:(?<normal>normal )?energy tariff is adjustable and is currently '.$number.' cents/kWh|(?<normalFi>normaali )?(?:energiatariffi|energiahinta) on muuttuva(?: ja on (?:nyt|tällä hetkellä)|, tällä hetkellä) (?<numberFi>[0-9]+(?:[.,][0-9]+)?) snt/kWh)',
            'adjustable_observation' => $scope.' (?<normalFi>normaali )?energiahinta on tällä hetkellä (?<numberFi>[0-9]+(?:[.,][0-9]+)?) snt/kWh ja voi muuttua',
            'discount' => $scope.' (?:energy price is the normal tariff minus '.$number.' (?<unit>cents/kWh|percent) '.$period.'(?:, with a minimum price of (?<floor>[0-9]+(?:[.,][0-9]+)?) cents/kWh)?|energiahinta on normaali energiatariffi miinus (?<numberFi>[0-9]+(?:[.,][0-9]+)?) (?<unitFi>snt/kWh|prosenttia) '.$this->finnishPeriod().'(?:, vähimmäishinta (?<floorFi>[0-9]+(?:[.,][0-9]+)?) snt/kWh)?)',
        ];
        foreach ($patterns as $kind => $pattern) {
            if (preg_match('~^'.$pattern.'$~uD', $text, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
                continue;
            }
            $kind = $kind === 'adjustable_observation' ? 'adjustable_tariff' : $kind;
            $amount = (float) str_replace(',', '.', $match['number'] ?? $match['numberFi']);
            if (! is_finite($amount)) {
                return null;
            }
            if ($kind === 'adjustable_tariff') {
                $starts = ['kind' => 'contract_start', 'value' => null];
                $ends = ['kind' => 'none', 'value' => null];
            } elseif (($months = $match['months'] ?? $match['monthsFi'] ?? $match['fiMonths'] ?? null) !== null) {
                $starts = ['kind' => 'contract_start', 'value' => null];
                $ends = ['kind' => 'after_months', 'value' => $months];
            } else {
                $starts = ['kind' => 'date', 'value' => $match['from'] ?? $match['fromFi'] ?? $match['fiFrom'] ?? null];
                $ends = ['kind' => 'date', 'value' => $match['to'] ?? $match['toFi'] ?? $match['fiTo'] ?? null];
            }
            $discount = $kind === 'discount';
            $unit = $match['unit'] ?? $match['unitFi'] ?? null;
            $floor = $match['floor'] ?? $match['floorFi'] ?? null;
            if (($floor !== null && ! is_finite((float) str_replace(',', '.', $floor)))
                || ($discount && in_array($unit, ['percent', 'prosenttia'], true) && $amount > 100)) {
                return null;
            }

            return [
                'observation_date' => $kind === 'adjustable_tariff' ? $analysisDate : null,
                'component_type' => self::SCOPES[$match['scope']],
                'normal' => ($match['normal'] ?? $match['normalFi'] ?? null) !== null,
                'kind' => $discount ? (in_array($unit, ['percent', 'prosenttia'], true) ? 'percentage_discount' : 'absolute_discount') : $kind,
                'amount' => $discount ? null : $amount,
                'discount_value' => $discount ? $amount : null,
                'floor_amount' => $floor === null ? null : (float) str_replace(',', '.', $floor),
                'starts' => $starts,
                'ends' => $ends,
            ];
        }

        return null;
    }

    private function finnishPeriod(): string
    {
        return '(?:(?:ensimmäiset|ensimmäisen) (?<fiMonths>[1-9][0-9]{0,2}) (?:kuukautta|kuukauden ajan)|ajalla (?<fiFrom>[0-9]{4}-[0-9]{2}-[0-9]{2}) – (?<fiTo>[0-9]{4}-[0-9]{2}-[0-9]{2}))';
    }

    private function finite(mixed $number): bool
    {
        return (is_int($number) || is_float($number)) && is_finite((float) $number);
    }

    private function sameNumber(mixed $a, mixed $b): bool
    {
        return $a === null || $b === null ? $a === $b : $this->finite($a) && $this->finite($b) && abs($a - $b) < 0.000001;
    }
}
