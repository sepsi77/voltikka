<?php

namespace App\Services;

use App\Models\SpotPriceAverage;
use App\Models\SpotPriceHour;
use Carbon\Carbon;

class SocialMediaPromptFormatter
{
    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';
    private const VAT_MULTIPLIER = 1.255;

    private const FINNISH_WEEKDAYS = [
        0 => 'Sunnuntai',
        1 => 'Maanantai',
        2 => 'Tiistai',
        3 => 'Keskiviikko',
        4 => 'Torstai',
        5 => 'Perjantai',
        6 => 'Lauantai',
    ];

    private const FINNISH_MONTHS = [
        1 => 'tammikuuta',
        2 => 'helmikuuta',
        3 => 'maaliskuuta',
        4 => 'huhtikuuta',
        5 => 'toukokuuta',
        6 => 'kesäkuuta',
        7 => 'heinäkuuta',
        8 => 'elokuuta',
        9 => 'syyskuuta',
        10 => 'lokakuuta',
        11 => 'marraskuuta',
        12 => 'joulukuuta',
    ];

    private const DAY_RATING_LABELS = [
        'very_cheap' => 'Erittäin halpa päivä',
        'cheap' => 'Edullinen päivä',
        'normal' => 'Normaalin hintainen päivä',
        'expensive' => 'Kallis päivä',
        'very_expensive' => 'Erittäin kallis päivä',
        'unknown' => 'Ei vertailutietoa',
    ];

    private const PROMPT_TEMPLATE_PATH = 'prompts/spot-price-today-social-media-update.md';

    public function formatPrompt(array $videoData): string
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);

        // Build the data markdown
        $dataMarkdown = $this->buildDataMarkdown($videoData, $helsinkiNow);

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

    private function buildDataMarkdown(array $videoData, Carbon $helsinkiNow): string
    {
        $sections = [
            $this->formatDate($helsinkiNow),
            $this->formatDayRating($videoData),
            $this->formatTodayPrices($videoData),
            $this->formatTomorrowPrices($videoData),
            $this->formatStatistics($videoData),
            $this->formatHistoricalComparison($videoData, $helsinkiNow),
            $this->formatApplianceRecommendations($videoData),
        ];

        return implode("\n\n", array_filter($sections));
    }

    private function buildFallbackPrompt(string $dataMarkdown): string
    {
        return <<<PROMPT
Olet Voltikka-sähköpalvelun someassistentti. Kirjoita lyhyt, hyödyllinen vinkki tämän päivän pörssisähkötilanteesta.

{$dataMarkdown}

## Tehtävä
Kirjoita 1-2 lauseen vinkki (max 180 merkkiä) joka antaa lukijalle konkreettisen toimintasuosituksen.

## Säännöt
- Max 180 merkkiä
- Luonnollista, rentoa suomea
- Ei hashtageja, ei emojeja
- Keskity yhteen näkökulmaan

**Vastaa vain vinkillä:**
PROMPT;
    }

    private function formatDate(Carbon $helsinkiNow): string
    {
        $weekday = self::FINNISH_WEEKDAYS[$helsinkiNow->dayOfWeek];
        $day = $helsinkiNow->day;
        $month = self::FINNISH_MONTHS[$helsinkiNow->month];
        $year = $helsinkiNow->year;

        return "## Päivämäärä\n**{$weekday} {$day}. {$month} {$year}**";
    }

    private function formatDayRating(array $videoData): string
    {
        $comparison = $videoData['comparison'] ?? [];
        $dayRating = $comparison['day_rating'] ?? null;

        if (!$dayRating || $dayRating['code'] === 'unknown') {
            return "## Päivän arvio\nEi riittävästi vertailutietoa.";
        }

        $label = self::DAY_RATING_LABELS[$dayRating['code']] ?? 'Normaali päivä';
        $description = $dayRating['description'] ?? '';

        return "## Päivän arvio\n**{$label}**" . ($description ? " – {$description}." : '.');
    }

    private function formatTodayPrices(array $videoData): string
    {
        $prices = $videoData['prices']['today'] ?? [];

        if (empty($prices)) {
            return "## Tämän päivän tuntihinnat\nEi saatavilla.";
        }

        return "## Tämän päivän tuntihinnat (c/kWh, sis. ALV)\n```\n" . $this->formatPriceTable($prices) . "\n```";
    }

    private function formatTomorrowPrices(array $videoData): string
    {
        $prices = $videoData['prices']['tomorrow'] ?? [];

        if (empty($prices)) {
            return "## Huomisen tuntihinnat\n*Ei vielä saatavilla.*";
        }

        return "## Huomisen tuntihinnat (c/kWh, sis. ALV)\n```\n" . $this->formatPriceTable($prices) . "\n```";
    }

    private function formatPriceTable(array $prices): string
    {
        // Sort by hour
        usort($prices, fn($a, $b) => $a['hour'] <=> $b['hour']);

        // Format as 4-column table (6 hours per column)
        $lines = [];
        for ($row = 0; $row < 6; $row++) {
            $cols = [];
            for ($col = 0; $col < 4; $col++) {
                $hour = $row + ($col * 6);
                $price = $this->findPriceForHour($prices, $hour);
                $priceStr = $price !== null ? number_format($price, 2, ',', '') : '-';
                $cols[] = sprintf('%02d:00 %6s', $hour, $priceStr);
            }
            $lines[] = implode('    ', $cols);
        }

        return implode("\n", $lines);
    }

    private function findPriceForHour(array $prices, int $hour): ?float
    {
        foreach ($prices as $price) {
            if ($price['hour'] === $hour) {
                return $price['price'] ?? null;
            }
        }
        return null;
    }

    private function formatStatistics(array $videoData): string
    {
        $stats = $videoData['statistics'] ?? [];

        $lines = ['## Tilastot'];

        if (isset($stats['cheapest_hour'])) {
            $lines[] = sprintf(
                '- **Halvin tunti:** %s (%s c/kWh)',
                $stats['cheapest_hour']['label'] ?? '-',
                $this->formatPrice($stats['cheapest_hour']['price'] ?? null)
            );
        }

        if (isset($stats['expensive_hour'])) {
            $lines[] = sprintf(
                '- **Kallein tunti:** %s (%s c/kWh)',
                $stats['expensive_hour']['label'] ?? '-',
                $this->formatPrice($stats['expensive_hour']['price'] ?? null)
            );
        }

        if (isset($stats['average'])) {
            $lines[] = sprintf('- **Keskihinta:** %s c/kWh', $this->formatPrice($stats['average']));
        }

        if (isset($stats['min']) && isset($stats['max'])) {
            $lines[] = sprintf(
                '- **Hintahaarukka:** %s – %s c/kWh',
                $this->formatPrice($stats['min']),
                $this->formatPrice($stats['max'])
            );
        }

        return implode("\n", $lines);
    }

    private function formatHistoricalComparison(array $videoData, Carbon $helsinkiNow): string
    {
        $comparison = $videoData['comparison'] ?? [];
        $stats = $videoData['statistics'] ?? [];
        $todayAvg = $stats['average'] ?? null;

        $lines = ['## Historiallinen vertailu'];

        if ($todayAvg !== null) {
            $lines[] = sprintf('- **Tänään keskimäärin:** %s c/kWh', $this->formatPrice($todayAvg));
        }

        // Yesterday
        if (isset($comparison['yesterday_average'])) {
            $change = $comparison['change_from_yesterday_percent'] ?? null;
            $changeStr = $this->formatChangePercent($change);
            $lines[] = sprintf('- **Eilen:** %s c/kWh%s', $this->formatPrice($comparison['yesterday_average']), $changeStr);
        }

        // Last week average
        $weeklyAvg = $this->getWeeklyAverage();
        if ($weeklyAvg !== null && $todayAvg !== null) {
            $change = $this->calculateChange($todayAvg, $weeklyAvg);
            $changeStr = $this->formatChangePercent($change);
            $lines[] = sprintf('- **Viime viikko (ka.):** %s c/kWh%s', $this->formatPrice($weeklyAvg), $changeStr);
        }

        // 30-day rolling average
        if (isset($comparison['rolling_30d_average'])) {
            $change = $comparison['change_from_30d_percent'] ?? null;
            $changeStr = $this->formatChangePercent($change);
            $lines[] = sprintf('- **30 päivän keskiarvo:** %s c/kWh%s', $this->formatPrice($comparison['rolling_30d_average']), $changeStr);
        }

        // Year over year
        $yearAgoAvg = $this->getYearAgoMonthlyAverage($helsinkiNow);
        if ($yearAgoAvg !== null && $todayAvg !== null) {
            $change = $this->calculateChange($todayAvg, $yearAgoAvg);
            $changeStr = $this->formatChangePercent($change);
            $monthName = self::FINNISH_MONTHS[$helsinkiNow->month];
            $lastYear = $helsinkiNow->year - 1;
            $lines[] = sprintf('- **Vuosi sitten (%s %d):** %s c/kWh%s', $monthName, $lastYear, $this->formatPrice($yearAgoAvg), $changeStr);
        }

        return implode("\n", $lines);
    }

    private function formatApplianceRecommendations(array $videoData): string
    {
        $lines = ['## Kodinkoneiden parhaat käyttöajat'];

        $appliances = $videoData['appliances'] ?? [];
        $evCharging = $videoData['ev_charging'] ?? null;
        $rolling30dAvg = $videoData['comparison']['rolling_30d_average'] ?? null;

        // EV Charging
        if ($evCharging) {
            $lines[] = $this->formatApplianceLine(
                'Sähköauton lataus',
                $evCharging['duration_hours'] ?? 3,
                $evCharging['power_kw'] ?? 3.7,
                $evCharging['time_label'] ?? '-',
                $evCharging['average_price'] ?? $evCharging['best_price'] ?? null,
                $evCharging['savings_euros'] ?? 0,
                $rolling30dAvg
            );
        }

        // Sauna
        if (isset($appliances['sauna'])) {
            $sauna = $appliances['sauna'];
            $lines[] = $this->formatApplianceLine(
                'Saunan lämmitys',
                $sauna['duration_hours'] ?? 1,
                $sauna['power_kw'] ?? 8,
                $sauna['best_hour_label'] ?? '-',
                $sauna['best_price'] ?? null,
                $sauna['savings_euros'] ?? 0,
                $rolling30dAvg
            );
        }

        // Laundry
        if (isset($appliances['laundry'])) {
            $laundry = $appliances['laundry'];
            $lines[] = $this->formatApplianceLine(
                'Pyykinpesu',
                $laundry['duration_hours'] ?? 2,
                $laundry['power_kw'] ?? 2,
                $laundry['time_label'] ?? '-',
                $laundry['average_price'] ?? null,
                $laundry['savings_euros'] ?? 0,
                $rolling30dAvg
            );
        }

        // Dishwasher
        if (isset($appliances['dishwasher'])) {
            $dishwasher = $appliances['dishwasher'];
            $lines[] = $this->formatApplianceLine(
                'Astianpesukone',
                $dishwasher['duration_hours'] ?? 2,
                $dishwasher['power_kw'] ?? 1.5,
                $dishwasher['time_label'] ?? '-',
                $dishwasher['average_price'] ?? null,
                $dishwasher['savings_euros'] ?? 0,
                $rolling30dAvg
            );
        }

        // Water heater
        if (isset($appliances['water_heater'])) {
            $waterHeater = $appliances['water_heater'];
            $lines[] = $this->formatApplianceLine(
                'Lämminvesivaraaja',
                $waterHeater['duration_hours'] ?? 1,
                $waterHeater['power_kw'] ?? 2.5,
                $waterHeater['best_hour_label'] ?? '-',
                $waterHeater['best_price'] ?? null,
                $waterHeater['savings_euros'] ?? 0,
                $rolling30dAvg
            );
        }

        return implode("\n", $lines);
    }

    private function formatApplianceLine(
        string $name,
        int $durationHours,
        float $powerKw,
        string $timeLabel,
        ?float $price,
        float $savingsEuros,
        ?float $rolling30dAvg
    ): string {
        $powerStr = $powerKw == (int) $powerKw
            ? number_format($powerKw, 0, ',', '')
            : number_format($powerKw, 1, ',', '');

        $line = sprintf('- **%s** (%dh @ %s kW): %s', $name, $durationHours, $powerStr, $timeLabel);

        // Add price
        if ($price !== null) {
            $line .= sprintf(' (%s c/kWh)', $this->formatPrice($price));
        }

        // Add comparison to 30d average
        if ($price !== null && $rolling30dAvg !== null && $rolling30dAvg > 0) {
            $percentDiff = (($price - $rolling30dAvg) / $rolling30dAvg) * 100;
            if (abs($percentDiff) >= 5) {
                $direction = $percentDiff > 0 ? 'kalliimpi' : 'halvempi';
                $line .= sprintf(' · %.0f%% %s kuin 30pv ka.', abs($percentDiff), $direction);
            }
        }

        // Add savings vs most expensive
        if ($savingsEuros >= 0.05) {
            $line .= sprintf(' · säästö %s€ vs kallein', $this->formatEuros($savingsEuros));
        }

        return $line;
    }

    private function formatPrice(?float $price): string
    {
        if ($price === null) {
            return '-';
        }
        return number_format($price, 2, ',', '');
    }

    private function formatEuros(float $euros): string
    {
        return number_format($euros, 2, ',', '');
    }

    private function formatChangePercent(?float $change): string
    {
        if ($change === null) {
            return '';
        }
        $sign = $change >= 0 ? '+' : '';
        return sprintf(' (%s%.1f%%)', $sign, $change);
    }

    private function calculateChange(float $current, float $reference): ?float
    {
        if ($reference <= 0) {
            return null;
        }
        return (($current - $reference) / $reference) * 100;
    }

    private function getWeeklyAverage(): ?float
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $weekStart = $helsinkiNow->copy()->subDays(7)->startOfDay()->setTimezone('UTC');
        $yesterdayEnd = $helsinkiNow->copy()->subDay()->endOfDay()->setTimezone('UTC');

        $prices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$weekStart, $yesterdayEnd])
            ->pluck('price_without_tax')
            ->toArray();

        if (empty($prices)) {
            return null;
        }

        $avgWithoutTax = array_sum($prices) / count($prices);
        return $avgWithoutTax * self::VAT_MULTIPLIER;
    }

    private function getYearAgoMonthlyAverage(Carbon $helsinkiNow): ?float
    {
        $lastYear = $helsinkiNow->year - 1;
        $month = $helsinkiNow->month;

        $monthStart = Carbon::create($lastYear, $month, 1, 0, 0, 0, self::TIMEZONE)
            ->startOfDay()->setTimezone('UTC');
        $monthEnd = Carbon::create($lastYear, $month, 1, 0, 0, 0, self::TIMEZONE)
            ->endOfMonth()->endOfDay()->setTimezone('UTC');

        $prices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$monthStart, $monthEnd])
            ->pluck('price_without_tax')
            ->toArray();

        if (empty($prices)) {
            return null;
        }

        $avgWithoutTax = array_sum($prices) / count($prices);
        return $avgWithoutTax * self::VAT_MULTIPLIER;
    }
}
