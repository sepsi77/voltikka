<?php

namespace App\Services;

use Carbon\Carbon;

class WeeklyOffersPromptFormatter
{
    private const TIMEZONE = 'Europe/Helsinki';

    private const PROMPT_TEMPLATE_PATH = 'prompts/weekly-offers-social-media.md';

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
        $templatePath = resource_path(self::PROMPT_TEMPLATE_PATH);
        if (!file_exists($templatePath)) {
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

        $section = "## Tarjousten yhteenveto\n";
        $section .= sprintf("**%d tarjousta** alennuksella tällä viikolla.\n", $offersCount);

        // Find best discount
        $bestDiscount = 0;
        $bestCompany = '';
        $bestDiscountType = '';

        foreach ($offers as $offer) {
            $discount = $offer['discount']['value'] ?? 0;
            $isPercentage = $offer['discount']['is_percentage'] ?? false;

            if ($isPercentage && $discount > $bestDiscount) {
                $bestDiscount = $discount;
                $bestCompany = $offer['company']['name'] ?? '';
                $bestDiscountType = 'percentage';
            }
        }

        if ($bestDiscount > 0 && $bestCompany) {
            $section .= sprintf(
                "\n**Viikon paras alennus:** %s tarjoaa %d%% alennuksen.",
                $bestCompany,
                (int) $bestDiscount
            );
        }

        // List companies
        $companies = array_column(array_column($offers, 'company'), 'name');
        if (!empty($companies)) {
            $section .= "\n\n**Yhtiöt:** " . implode(', ', $companies);
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
        $companyName = $offer['company']['name'] ?? 'Tuntematon';
        $contractName = $offer['name'] ?? '';
        $pricingModel = $offer['pricing_model'] ?? '';
        $pricingLabel = self::PRICING_MODEL_LABELS[$pricingModel] ?? $pricingModel;

        $section = "\n### {$number}. {$companyName}\n";
        $section .= "**Sopimus:** {$contractName}\n";
        $section .= "**Tyyppi:** {$pricingLabel}\n";

        // Discount details
        $discount = $offer['discount'] ?? [];
        if (!empty($discount)) {
            $discountValue = $discount['value'] ?? 0;
            $isPercentage = $discount['is_percentage'] ?? false;
            $nMonths = $discount['n_first_months'] ?? null;
            $untilDate = $discount['until_date'] ?? null;

            $discountStr = $isPercentage
                ? sprintf('%d%% alennus', (int) $discountValue)
                : sprintf('%.2f c/kWh alennus', $discountValue);

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
        if (!empty($pricing)) {
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

        if (!empty($costs)) {
            $section .= "\n**Vuosikustannukset alennuksella:**\n";
            $section .= sprintf("- Kerrostalo (2000 kWh): %d €/vuosi", $costs['apartment'] ?? 0);
            if (!empty($savings['apartment']) && $savings['apartment'] > 0) {
                $section .= sprintf(' (säästö %d €)', $savings['apartment']);
            }
            $section .= "\n";

            $section .= sprintf("- Rivitalo (5000 kWh): %d €/vuosi", $costs['townhouse'] ?? 0);
            if (!empty($savings['townhouse']) && $savings['townhouse'] > 0) {
                $section .= sprintf(' (säästö %d €)', $savings['townhouse']);
            }
            $section .= "\n";

            $section .= sprintf("- Omakotitalo (10000 kWh): %d €/vuosi", $costs['house'] ?? 0);
            if (!empty($savings['house']) && $savings['house'] > 0) {
                $section .= sprintf(' (säästö %d €)', $savings['house']);
            }
            $section .= "\n";
        }

        return $section;
    }
}
