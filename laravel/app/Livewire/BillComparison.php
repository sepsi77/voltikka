<?php

namespace App\Livewire;

use App\Services\BillComparison\BillComparisonService;
use App\Services\DTO\BillComparisonRequest;
use App\Services\DTO\BillComparisonResult;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * "Maksatko sähköstä liikaa?" — bill-anchored contract comparison tool.
 *
 * URL: /maksatko-liikaa
 *
 * The visitor enters what is printed on their electricity bill for one billing
 * period: date range, period consumption (kWh) and the total they paid for the
 * energy-contract portion (excluding siirto). We compute what each active
 * market contract would have cost for that same period + consumption and slot
 * the user's bill into the ranking, leading with monthly + annualized savings.
 *
 * No new models or DB writes — pure compute, ephemeral. See
 * `app/Services/BillComparison/AGENTS.md` for the comparison semantics.
 */
class BillComparison extends Component
{
    /**
     * VAT multiplier used to lift a pre-VAT total to Voltikka's with-VAT basis.
     */
    public const VAT_MULTIPLIER = 1.255;

    /**
     * Period preset keys. Last 3 full calendar months plus a custom range.
     */
    public string $periodPreset = 'last_month';

    public string $startDate = '';
    public string $endDate = '';

    public float|string|null $kwh = null;
    public float|string|null $totalEur = null;
    public bool $includesVat = true;
    public bool $includesHeating = false;

    // Optional: if the visitor knows their annual consumption, use it directly
    // instead of the seasonal-profile annualization for the savings estimate.
    public float|string|null $annualKwh = null;

    // Optional explanatory inputs (never used for the counterfactual).
    public float|string|null $energyPriceCents = null;
    public float|string|null $baseFeeEur = null;

    public array $presetLabels = [];

    /**
     * Derived state that is intentionally NOT public, so Livewire never syncs
     * it into the component snapshot.
     *
     * `$resultArray` is a large (300+ row) DB-derived nested array. Livewire's
     * deep-array dehydration produces a snapshot whose checksum its own verify
     * step cannot reproduce, which raised `CorruptComponentPayloadException` on
     * every update (the page silently froze on stale numbers). It is also
     * recomputed from the inputs on every request via `calculate()`, so syncing
     * it across the wire bought nothing while shipping ~168 KB per keystroke.
     * Keeping these protected drops the snapshot to a few hundred bytes and the
     * result is rebuilt each request and handed to the view from `render()`.
     */
    protected bool $calculated = false;
    protected ?array $resultArray = null;
    protected ?string $errorMessage = null;

    public function mount(): void
    {
        $this->syncPresetLabels();
        $this->applyPreset();

        // Lead with realistic defaults for a typical household monthly bill.
        // Most Finns use ~2000–5000 kWh/year, so a one-month bill is roughly
        // 150–400 kWh; 400 kWh + ~35 € is a plausible mid-range starting point.
        if ($this->kwh === null || $this->kwh === '') {
            $this->kwh = 400;
        }
        if ($this->totalEur === null || $this->totalEur === '') {
            $this->totalEur = 35.00;
        }

        $this->calculate();
    }

    public function updatedPeriodPreset(): void
    {
        if ($this->periodPreset !== 'custom') {
            $this->applyPreset();
            $this->calculate();
        }
    }

    /**
     * Click handler for the preset chips (keeps custom date entry reactive).
     */
    public function setPeriodPreset(string $key): void
    {
        $this->periodPreset = $key;
        if ($key !== 'custom') {
            $this->applyPreset();
        }
        $this->calculate();
    }

    public function updatedStartDate(): void
    {
        if ($this->periodPreset !== 'custom') {
            $this->periodPreset = 'custom';
        }
        // The comparison period drives the whole counterfactual, so a manual
        // date edit must recompute (otherwise results would go stale now that
        // the result is no longer persisted in the snapshot).
        $this->calculate();
    }

    public function updatedEndDate(): void
    {
        if ($this->periodPreset !== 'custom') {
            $this->periodPreset = 'custom';
        }
        $this->calculate();
    }

    public function updatedKwh(): void
    {
        $this->calculate();
    }

    public function updatedTotalEur(): void
    {
        $this->calculate();
    }

    public function updatedIncludesVat(): void
    {
        $this->calculate();
    }

    public function updatedIncludesHeating(): void
    {
        $this->calculate();
    }

    public function updatedAnnualKwh(): void
    {
        $this->calculate();
    }

    public function updatedEnergyPriceCents(): void
    {
        $this->calculate();
    }

    public function updatedBaseFeeEur(): void
    {
        $this->calculate();
    }

    public function calculate(): void
    {
        $this->errorMessage = null;

        $kwh = $this->nullableFloat($this->kwh);
        $totalEur = $this->nullableFloat($this->totalEur);

        if ($kwh === null || $kwh <= 0) {
            $this->calculated = false;
            $this->resultArray = null;
            return;
        }

        if ($totalEur === null || $totalEur <= 0) {
            $this->calculated = false;
            $this->resultArray = null;
            return;
        }

        $start = $this->parseDate($this->startDate);
        $end = $this->parseDate($this->endDate);

        if ($start === null || $end === null || $end < $start) {
            $this->errorMessage = 'Tarkista laskutusjakson päivämäärät.';
            $this->calculated = false;
            $this->resultArray = null;
            return;
        }

        // Normalize to Voltikka's comparable basis: energy only, with ALV 25.5 %.
        $comparableTotal = $this->includesVat ? $totalEur : ($totalEur * self::VAT_MULTIPLIER);

        try {
            $service = app(BillComparisonService::class);

            $request = new BillComparisonRequest(
                startDate: Carbon::parse($start, 'Europe/Helsinki'),
                endDate: Carbon::parse($end, 'Europe/Helsinki'),
                kwh: $kwh,
                userTotalEur: $comparableTotal,
                includesHeating: $this->includesHeating,
                energyPriceCents: $this->nullableFloat($this->energyPriceCents),
                baseFeeEur: $this->nullableFloat($this->baseFeeEur),
                annualKwhOverride: $this->nullableFloat($this->annualKwh),
            );

            $result = $service->compare($request);
            $this->resultArray = $this->resultToArray($result);
            $this->calculated = true;

            $this->dispatch('track',
                eventName: 'Bill Comparison Completed',
                props: [
                    'kwh' => $kwh,
                    'total_eur' => $comparableTotal,
                    'includes_vat' => $this->includesVat,
                    'includes_heating' => $this->includesHeating,
                    'user_rank' => $result->userRank,
                    'total_contracts' => $result->totalContracts,
                    'annual_saving' => round($result->annualSavingEur, 2),
                ]
            );
        } catch (\Throwable $e) {
            report($e);
            $this->errorMessage = 'Laskennassa tapahtui virhe. Tarkista syöttämäsi tiedot ja yritä uudelleen.';
            $this->calculated = false;
            $this->resultArray = null;
        }
    }

    #[Computed]
    public function hasResults(): bool
    {
        return $this->calculated && ! empty($this->resultArray);
    }

    public function getJsonLdSchema(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebApplication',
            'name' => 'Maksatko sähköstä liikaa?',
            'description' => 'Vertaa sähkölaskuasi markkinoiden sähkösopimuksiin ja näe, säästäisitko vaihtamalla. Syötä laskun tiedot, et tarvitse vuosikulutusta.',
            'url' => route('bill.comparison'),
            'applicationCategory' => 'UtilitiesApplication',
            'operatingSystem' => 'Any',
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'EUR',
            ],
        ];
    }

    /**
     * Single source of truth for the FAQ block and FAQPage JSON-LD.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    public function getFaqItemsProperty(): array
    {
        return [
            [
                'question' => 'Mistä tiedän, maksanko sähköstä liikaa?',
                'answer' => 'Syötä sähkölaskusi tiedot Voltikan laskuriin: laskutusjakso, kulutus kilowattitunteina ja sähkösopimuksen hinta (ilman sähkön siirtoa). Laskuri näyttää heti, kuinka paljon säästäisit tällä kulutuksella muilla markkinoiden sopimuksilla ja millä sijalla oma sopimuksesi on.',
            ],
            [
                'question' => 'Mikä hinta laskuriin syötetään, sisältääkö se alvin ja siirron?',
                'answer' => 'Syötä vain sähkösopimuksen hinta, eli se mitä maksat sähköstä itsestään. Sähkön siirto (verkkomaksu) kuuluu erilliseen laskuun tai eriin riviin, eikä sitä voi säästää vaihtamalla sopimusta. Useimmat syöttävät verollisen hinnan; voit merkitä "Sisältää ALV" -valinnan, jolloin vertailu menee oikein.',
            ],
            [
                'question' => 'Miksi vuosikulutusta ei tarvitse tietää?',
                'answer' => 'Laskuri perustuu yhden laskutusjakson todelliseen kulutukseen ja laskuun. Sama jakso ja kWh syötetään jokaiselle markkinoiden sopimukselle, jolloin näet tismalleen, mitä olisit maksanut muulla sopimuksella. Vuosisäästö arvioidaan kausivaihtelun huomioiden sen perusteella, onko sähkölämmitys mukana kulutuksessa.',
            ],
            [
                'question' => 'Onko pörssisähkön säästöarvio luotettava?',
                'answer' => 'Laskutusjakson osalta laskuri käyttää todellisia toteutuneita pörssihintoja, joten se on tarkka sille jaksolle. Vuosisäästöarvio on kuitenkin suuntaa-antava, koska pörssisähkön hinta vaihtelee jatkuvasti. Laskuri näyttää jakson keskimääräisen pörssihinnan, johon arvio perustuu.',
            ],
            [
                'question' => 'Kuinka paljon sähkön vaihtaminen kannattaa?',
                'answer' => 'Jo pelkkä kalliimman sopimuksen vaihtaminen markkinoiden halvimpaan voi säästää satoja euroja vuodessa. Laskuri näyttää sekä kuukausittaisen että vuotuisen arvion säästöstä ja linkittää suoraan edullisimpiin sopimuksiin, joita voit hakea.',
            ],
        ];
    }

    public function buildFaqJsonLd(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $faq): array => [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ],
            ], $this->faqItems),
        ];
    }

    public function render()
    {
        // The result is no longer persisted in the snapshot, so it must exist
        // for every render path. All interactive hooks call calculate(), but
        // this guard keeps any other re-render safe; it early-returns cheaply
        // when inputs are incomplete and never re-dispatches analytics once a
        // calculation has succeeded.
        if (! $this->calculated && $this->errorMessage === null) {
            $this->calculate();
        }

        $year = date('Y');

        return view('livewire.bill-comparison', [
            'jsonLd' => $this->getJsonLdSchema(),
            'faqJsonLd' => $this->buildFaqJsonLd(),
            'resultArray' => $this->resultArray,
            'errorMessage' => $this->errorMessage,
        ])->layout('layouts.app', [
            'title' => "Maksatko sähköstä liikaa? Vertaa laskuasi markkinoihin {$year} | Voltikka",
            'metaDescription' => "Syötä sähkölaskusi tiedot ja näe heti, säästäisitko vaihtamalla. Voltikka vertaa laskuasi markkinoiden sähkösopimuksiin, et tarvitse vuosikulutusta. Ilmainen laskuri.",
            'canonical' => route('bill.comparison'),
        ]);
    }

    private function applyPreset(): void
    {
        $today = Carbon::today('Europe/Helsinki');

        // Presets cover only fully completed billing months — the current
        // month is not billed yet, so they start from last month backwards.
        $start = match ($this->periodPreset) {
            'last_month' => $today->copy()->subMonthNoOverflow()->startOfMonth(),
            'month_before' => $today->copy()->subMonthsNoOverflow(2)->startOfMonth(),
            'two_months_before' => $today->copy()->subMonthsNoOverflow(3)->startOfMonth(),
            default => null,
        };

        if ($start === null) {
            return; // custom — keep user-entered dates
        }

        $end = $start->copy()->endOfMonth();

        $this->startDate = $start->toDateString();
        $this->endDate = $end->toDateString();
    }

    private function syncPresetLabels(): void
    {
        $today = Carbon::today('Europe/Helsinki');

        $last = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $before = $today->copy()->subMonthsNoOverflow(2)->startOfMonth();
        $twoBefore = $today->copy()->subMonthsNoOverflow(3)->startOfMonth();

        $this->presetLabels = [
            'last_month' => $this->monthLabel($last),
            'month_before' => $this->monthLabel($before),
            'two_months_before' => $this->monthLabel($twoBefore),
            'custom' => 'Muu jakso',
        ];
    }

    private function monthLabel(Carbon $date): string
    {
        $fiMonths = [
            1 => 'tammikuu', 2 => 'helmikuu', 3 => 'maaliskuu', 4 => 'huhtikuu',
            5 => 'toukokuu', 6 => 'kesäkuu', 7 => 'heinäkuu', 8 => 'elokuu',
            9 => 'syyskuu', 10 => 'lokakuu', 11 => 'marraskuu', 12 => 'joulukuu',
        ];

        $month = (int) $date->format('n');
        $year = $date->format('Y');

        return ucfirst($fiMonths[$month]).' '.$year;
    }

    /**
     * Public so the view can guard optional-input explanation logic.
     */
    public function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function parseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'Europe/Helsinki');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Serialize the result for Livewire's public-property round-trip while
     * keeping row data flat and view-friendly.
     */
    private function resultToArray(BillComparisonResult $result): array
    {
        $rows = array_map(function ($row) use ($result) {
            return [
                'contract_id' => $row->contractId,
                'name' => $row->name,
                'company_name' => $row->companyName,
                'company_slug' => $row->companySlug,
                'pricing_model' => $row->pricingModel,
                'is_spot' => $row->isSpot,
                'spot_margin' => $row->spotMargin,
                'has_promo' => $row->hasPromo,
                'is_user' => $row->isUser,
                'period_cost_eur' => round($row->periodCostEur, 2),
                'annual_cost_eur' => $row->annualCostEur !== null ? round($row->annualCostEur, 2) : null,
                'implied_cents_per_kwh' => round($row->impliedCentsPerKwh, 2),
                'saving_per_month_eur' => $row->savingPerMonthEur($result->userAnnualCost) !== null
                    ? round($row->savingPerMonthEur($result->userAnnualCost), 2)
                    : null,
                'detail_url' => $row->detailUrl,
            ];
        }, $result->rows);

        return [
            'rows' => $rows,
            'user_implied_cents_per_kwh' => round($result->userImpliedCentsPerKwh, 2),
            'user_annual_cost' => round($result->userAnnualCost, 2),
            'user_rank' => $result->userRank,
            'total_contracts' => $result->totalContracts,
            'period_saving_eur' => round($result->periodSavingEur, 2),
            'annual_saving_eur' => round($result->annualSavingEur, 2),
            'monthly_saving_eur' => round($result->monthlySavingEur, 2),
            'is_overpaying' => $result->isOverpaying(),
            'spot_avg_cents_per_kwh' => $result->spotAvgCentsPerKwh !== null
                ? round($result->spotAvgCentsPerKwh, 2)
                : null,
            'has_spot_in_top' => $result->hasSpotInTop,
            'annual_kwh' => $result->annualKwh,
            'warnings' => $result->warnings,
        ];
    }
}
