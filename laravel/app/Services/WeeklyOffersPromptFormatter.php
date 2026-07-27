<?php

namespace App\Services;

class WeeklyOffersPromptFormatter
{
    private const TIMEZONE = 'Europe/Helsinki';

    private const LEGACY_PROMPT_TEMPLATE_PATH = 'prompts/weekly-offers-social-media.md';

    private const CANONICAL_PROMPT_TEMPLATE_PATH = 'prompts/weekly-offers-social-media-canonical.md';

    private const PRICING_MODEL_LABELS = [
        'Spot' => 'Pörssisähkö',
        'FixedPrice' => 'Kiinteä hinta',
        'Hybrid' => 'Hybridisopimus',
    ];

    public function formatPrompt(array $videoData): string
    {
        // Build the data markdown
        $dataMarkdown = $this->buildDataMarkdown($videoData);

        // Load the prompt template
        $templatePath = resource_path(
            ($videoData['pricing_basis'] ?? null) === 'canonical'
                ? self::CANONICAL_PROMPT_TEMPLATE_PATH
                : self::LEGACY_PROMPT_TEMPLATE_PATH
        );
        if (! file_exists($templatePath)) {
            // Fallback to inline template if file doesn't exist
            return $this->buildFallbackPrompt($dataMarkdown);
        }

        $template = file_get_contents($templatePath);

        // Inject the data markdown
        return str_replace('{{DATA_MARKDOWN}}', $dataMarkdown, $template);
    }

    private function buildDataMarkdown(array $videoData): string
    {
        $sections = [
            $this->formatWeekPeriod($videoData),
            $this->formatOffersSummary($videoData),
            $this->formatOfferDetails($videoData),
        ];

        return implode("\n\n", array_filter($sections));
    }

    private function buildFallbackPrompt(string $dataMarkdown): string
    {
        return <<<PROMPT
Olet Voltikka-sähköpalvelun someassistentti. Kirjoita lyhyt, hyödyllinen vinkki viikon sähkötarjouksista.

{$dataMarkdown}

## Tehtävä
Kirjoita 1-2 lauseen vinkki (max 180 merkkiä) joka antaa lukijalle konkreettisen toimintasuosituksen.

## Säännöt
- Max 180 merkkiä
- Luonnollista, rentoa suomea
- Ei hashtageja, ei emojeja
- Keskity yhteen näkökulmaan

**Vastaa vain JSON-muodossa:**
{
  "twitter": "Vinkki tähän...",
  "tiktok": "Vinkki tähän...",
  "instagram": "Vinkki tähän...",
  "youtube": "Vinkki tähän..."
}
PROMPT;
    }

    private function formatWeekPeriod(array $videoData): string
    {
        $formatted = $videoData['week']['formatted'] ?? 'Tämä viikko';
        $start = $videoData['week']['start'] ?? null;
        $end = $videoData['week']['end'] ?? null;

        $section = "## Viikko\n**{$formatted}**";

        if ($start && $end) {
            $section .= "\n({$start} – {$end})";
        }

        return $section;
    }

    private function formatOffersSummary(array $videoData): string
    {
        $offersCount = $videoData['offers_count'] ?? 0;
        $offers = $videoData['offers'] ?? [];
        $canonical = ($videoData['pricing_basis'] ?? null) === 'canonical';

        $section = "## Tarjousten yhteenveto\n";
        $section .= $canonical
            ? sprintf("**%d tarjousta**, joissa on kanonisesti laskettu positiivinen etu.\n", $offersCount)
            : sprintf("**%d tarjousta** alennuksella tällä viikolla.\n", $offersCount);

        if (! $canonical) {
            $bestDiscount = 0;
            $bestCompany = '';

            foreach ($offers as $offer) {
                $discount = $offer['discount']['value'] ?? 0;
                $isPercentage = $offer['discount']['is_percentage'] ?? false;

                if ($isPercentage && $discount > $bestDiscount) {
                    $bestDiscount = $discount;
                    $bestCompany = $offer['company']['name'] ?? '';
                }
            }

            if ($bestDiscount > 0 && $bestCompany) {
                $section .= sprintf(
                    "\n**Viikon paras alennus:** %s tarjoaa %d%% alennuksen.",
                    $bestCompany,
                    (int) $bestDiscount
                );
            }
        }

        $companies = array_column(array_column($offers, 'company'), 'name');
        if (! empty($companies)) {
            $section .= "\n\n**Yhtiöt:** ".implode(', ', $companies);
        }

        return $section;
    }

    private function formatOfferDetails(array $videoData): string
    {
        $offers = $videoData['offers'] ?? [];

        if (empty($offers)) {
            return "## Tarjoukset\nEi tarjouksia saatavilla.";
        }

        $section = "## Tarjoukset yksityiskohtaisesti\n";

        foreach ($offers as $index => $offer) {
            $section .= $this->formatSingleOffer($offer, $index + 1);
        }

        return $section;
    }

    private function formatSingleOffer(array $offer, int $number): string
    {
        if (($offer['pricing_basis'] ?? null) === 'canonical') {
            return $this->formatCanonicalOffer($offer, $number);
        }

        $companyName = $offer['company']['name'] ?? 'Tuntematon';
        $contractName = $offer['name'] ?? '';
        $pricingModel = $offer['pricing_model'] ?? '';
        $pricingLabel = self::PRICING_MODEL_LABELS[$pricingModel] ?? $pricingModel;

        $section = "\n### {$number}. {$companyName}\n";
        $section .= "**Sopimus:** {$contractName}\n";
        $section .= "**Tyyppi:** {$pricingLabel}\n";

        // Discount details
        $discount = $offer['discount'] ?? [];
        if (! empty($discount)) {
            $discountValue = $discount['value'] ?? 0;
            $isPercentage = $discount['is_percentage'] ?? false;
            $nMonths = $discount['n_first_months'] ?? null;
            $untilDate = $discount['until_date'] ?? null;

            $discountStr = $discount['formatted'] ?? null;
            if (! $discountStr) {
                $discountStr = $isPercentage
                    ? sprintf('%d%% alennus', (int) $discountValue)
                    : sprintf('%.2f alennus', $discountValue);
            }

            $section .= "**Alennus:** {$discountStr}";

            if ($nMonths) {
                $section .= sprintf(' (ensimmäiset %d kuukautta)', $nMonths);
            } elseif ($untilDate) {
                $section .= sprintf(' (voimassa %s asti)', $untilDate);
            }
            $section .= "\n";
        }

        // Pricing
        $pricing = $offer['pricing'] ?? [];
        if (! empty($pricing)) {
            $monthlyFee = $pricing['monthly_fee'] ?? null;
            $energyPrice = $pricing['energy_price'] ?? null;

            if ($monthlyFee !== null) {
                $section .= sprintf("**Kuukausimaksu:** %.2f €/kk\n", $monthlyFee);
            }
            if ($energyPrice !== null) {
                $section .= sprintf("**Energiahinta:** %.2f c/kWh\n", $energyPrice);
            }
        }

        // Costs by housing type
        $costs = $offer['costs'] ?? [];
        $savings = $offer['savings'] ?? [];

        if (! empty($costs)) {
            $section .= "\n**Vuosikustannukset alennuksella:**\n";
            $section .= sprintf('- Kerrostalo (2000 kWh): %d €/vuosi', $costs['apartment'] ?? 0);
            if (! empty($savings['apartment']) && $savings['apartment'] > 0) {
                $section .= sprintf(' (säästö %d €)', $savings['apartment']);
            }
            $section .= "\n";

            $section .= sprintf('- Rivitalo (5000 kWh): %d €/vuosi', $costs['townhouse'] ?? 0);
            if (! empty($savings['townhouse']) && $savings['townhouse'] > 0) {
                $section .= sprintf(' (säästö %d €)', $savings['townhouse']);
            }
            $section .= "\n";

            $section .= sprintf('- Omakotitalo (10000 kWh): %d €/vuosi', $costs['house'] ?? 0);
            if (! empty($savings['house']) && $savings['house'] > 0) {
                $section .= sprintf(' (säästö %d €)', $savings['house']);
            }
            $section .= "\n";
        }

        return $section;
    }

    /**
     * Format only typed canonical offer output. Do not reconstruct a percentage,
     * component discount, or annual customer benefit from relational metadata.
     *
     * @param  array<string, mixed>  $offer
     */
    private function formatCanonicalOffer(array $offer, int $number): string
    {
        $companyName = $offer['company']['name'] ?? 'Tuntematon';
        $contractName = $offer['name'] ?? '';
        $pricingModel = $offer['pricing_model'] ?? '';
        $pricingLabel = self::PRICING_MODEL_LABELS[$pricingModel] ?? $pricingModel;
        $fact = is_array($offer['offer'] ?? null) ? $offer['offer'] : [];

        $section = "\n### {$number}. {$companyName}\n";
        $section .= "**Sopimus:** {$contractName}\n";
        $section .= "**Tyyppi:** {$pricingLabel}\n";

        if (is_numeric($fact['benefit_eur'] ?? null) && is_numeric($fact['basis_months'] ?? null)) {
            $section .= sprintf(
                "**Mitattu tarjousetu:** %s / %d kk (%s)\n",
                $this->euros((float) $fact['benefit_eur']),
                (int) $fact['basis_months'],
                $fact['basis_label'] ?? 'ilmoitettu vertailujakso',
            );
        }

        $pricing = is_array($offer['pricing'] ?? null) ? $offer['pricing'] : [];
        $priceLines = $this->canonicalPricingLines($pricing);
        if ($priceLines !== []) {
            $section .= '**Nykyiset kanoniset hinnat:** '.implode(', ', $priceLines)."\n";
        }

        $profiles = [
            'apartment' => 'Kerrostalo (2 000 kWh)',
            'townhouse' => 'Rivitalo (5 000 kWh)',
            'house' => 'Omakotitalo (10 000 kWh)',
        ];
        $consumptions = is_array($offer['consumptions'] ?? null) ? $offer['consumptions'] : [];

        $section .= "\n**Kanoniset hintavertailut:**\n";
        foreach ($profiles as $key => $label) {
            $result = is_array($consumptions[$key] ?? null) ? $consumptions[$key] : [];
            if (($result['availability'] ?? null) !== 'available'
                || ! is_numeric($result['total_cost'] ?? null)
                || ! is_numeric($result['normal_total_cost'] ?? null)) {
                $section .= "- {$label}: hinta ei ole saatavilla ({$result['comparability']})\n";

                continue;
            }

            $section .= sprintf(
                '- %s: %s (%s), normaalihinta %s, keskimäärin %s/kk',
                $label,
                $this->euros((float) $result['total_cost']),
                $result['total_basis_label'] ?? 'vertailuhinta',
                $this->euros((float) $result['normal_total_cost']),
                $this->euros((float) $result['avg_monthly_cost']),
            );

            if (is_numeric($result['customer_benefit_eur'] ?? null)
                && is_numeric($result['customer_benefit_basis_months'] ?? null)) {
                $section .= sprintf(
                    ', mitattu etu %s / %d kk',
                    $this->euros((float) $result['customer_benefit_eur']),
                    (int) $result['customer_benefit_basis_months'],
                );
            }

            if (($result['total_basis'] ?? null) === 'annualized_contract_term'
                && is_array($result['contract_term'] ?? null)
                && is_numeric($result['contract_term']['total_cost'] ?? null)
                && is_numeric($result['contract_term']['months'] ?? null)) {
                $section .= sprintf(
                    '; todellinen %d kk sopimuskausi %s',
                    (int) $result['contract_term']['months'],
                    $this->euros((float) $result['contract_term']['total_cost']),
                );
            }

            if (($result['is_estimate'] ?? false) === true) {
                $section .= ', arvio ('.($result['estimate_method'] ?? 'menetelmä ei ilmoitettu').')';
            }

            $section .= "\n";
        }

        return $section;
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @return list<string>
     */
    private function canonicalPricingLines(array $pricing): array
    {
        $fields = [
            'monthly_fee' => ['Perusmaksu', '€/kk'],
            'general_kwh_price' => ['Energia', 'c/kWh'],
            'daytime_kwh_price' => ['Päiväenergia', 'c/kWh'],
            'nighttime_kwh_price' => ['Yöenergia', 'c/kWh'],
            'seasonal_winter_day_kwh_price' => ['Talvipäivä', 'c/kWh'],
            'seasonal_other_kwh_price' => ['Muu kausi', 'c/kWh'],
            'spot_price_margin' => ['Marginaali', 'c/kWh'],
        ];
        $lines = [];

        foreach ($fields as $key => [$label, $unit]) {
            if (is_numeric($pricing[$key] ?? null)) {
                $lines[] = $label.' '.number_format((float) $pricing[$key], 2, ',', ' ').' '.$unit;
            }
        }

        return $lines;
    }

    private function euros(float $value): string
    {
        return number_format($value, 2, ',', ' ').' €';
    }
}
