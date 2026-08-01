<?php

namespace App\Livewire\Concerns;

use App\Livewire\BillComparison;
use App\Services\DTO\BillComparisonRequest;
use Carbon\Carbon;

/**
 * The bill-entry inputs shared by every surface that takes one electricity bill
 * and prices it against Voltikka's own contract data.
 *
 * Three surfaces exist and they must not drift apart:
 *  - `/maksatko-liikaa` (`BillComparison`) — the standalone tool. It keeps its
 *    own property names because it also owns the annualized hero, the ranking
 *    table and the optional `annualKwh` override.
 *  - the in-listing mode (`ContractsList`) — period basis only.
 *  - the contract detail module (`ContractDetail`) — period basis only, one
 *    contract.
 *
 * The last two share this trait **and** the Blade partial
 * `resources/views/partials/bill-comparison-form.blade.php`, which binds
 * exactly these property names. Add a field here, not in one template.
 *
 * The bill total is the anchor: we never model the visitor's pricing model,
 * day/night split or margin. See `app/Services/BillComparison/AGENTS.md`.
 */
trait BillComparisonInputs
{
    /**
     * Whether a valid bill has been entered. Interactive state only, never
     * `#[Url]`, so a fresh GET always starts without a bill and the cached
     * default payloads are unaffected.
     */
    public bool $billActive = false;

    /**
     * Bill period preset: last 3 completed calendar months plus 'custom'.
     */
    public string $billPeriodPreset = 'last_month';

    public string $billStartDate = '';

    public string $billEndDate = '';

    /**
     * Numeric bill inputs are string|null tolerant because mobile browsers can
     * send an empty string while a number field is being cleared.
     */
    public float|string|null $billKwh = null;

    public float|string|null $billTotalEur = null;

    /** @var array<string, string> */
    public array $billInputNotices = [];

    public bool $billIncludesVat = true;

    public bool $billIncludesHeating = false;

    /**
     * Finnish month labels for the period preset chips, refreshed per request.
     *
     * @var array<string, string>
     */
    public array $billPresetLabels = [];

    /**
     * Recompute whatever the surface derives from the bill. Called after every
     * input change; each component owns its own invalidation and analytics.
     */
    abstract protected function recomputeBill(): void;

    /**
     * Keep the preset labels current and seed default dates. Call from the
     * component's `booted()` so the form is always usable.
     */
    protected function syncBillInputDefaults(): void
    {
        $this->billPresetLabels = $this->computeBillPresetLabels();

        if ($this->billStartDate === '' || $this->billEndDate === '') {
            $this->applyBillPreset();
        }
    }

    public function setBillPeriodPreset(string $key): void
    {
        $this->billPeriodPreset = $key;

        if ($key !== 'custom') {
            $this->applyBillPreset();
        }

        $this->recomputeBill();
    }

    public function updatedBillStartDate(): void
    {
        $this->billPeriodPreset = 'custom';
        $this->recomputeBill();
    }

    public function updatedBillEndDate(): void
    {
        $this->billPeriodPreset = 'custom';
        $this->recomputeBill();
    }

    public function updatedBillKwh(): void
    {
        $this->normalizePositiveBillInput(
            'billKwh',
            'Kulutuksen pitää olla suurempi kuin 0 kWh.',
        );
        $this->recomputeBill();
    }

    public function updatedBillTotalEur(): void
    {
        $this->normalizePositiveBillInput(
            'billTotalEur',
            'Laskun summan pitää olla suurempi kuin 0 €.',
        );
        $this->recomputeBill();
    }

    public function updatedBillIncludesVat(): void
    {
        $this->recomputeBill();
    }

    public function updatedBillIncludesHeating(): void
    {
        $this->recomputeBill();
    }

    /**
     * Whether this surface offers the bill entry at all. Overridden by
     * `ContractsList` (rollout switch) and `ContractDetail` (active contracts).
     */
    protected function billInputsEnabled(): bool
    {
        return true;
    }

    protected function isBillInputValid(): bool
    {
        return $this->billInputsEnabled()
            && $this->billKwhValue() > 0
            && $this->billTotalValue() > 0
            && $this->billDatesValid();
    }

    protected function billFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    protected function normalizePositiveBillInput(string $property, string $message): void
    {
        $value = $this->billFloat($this->{$property});

        if ($value !== null && $value <= 0) {
            $this->{$property} = null;
            $this->billInputNotices[$property] = $message;

            return;
        }

        unset($this->billInputNotices[$property]);
    }

    protected function billKwhValue(): float
    {
        $value = $this->billFloat($this->billKwh);

        return ($value !== null && $value > 0) ? $value : 0.0;
    }

    protected function billTotalValue(): float
    {
        $value = $this->billFloat($this->billTotalEur);

        return ($value !== null && $value > 0) ? $value : 0.0;
    }

    protected function billParseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'Europe/Helsinki');
        } catch (\Throwable) {
            return null;
        }
    }

    protected function billDatesValid(): bool
    {
        $start = $this->billParseDate($this->billStartDate);
        $end = $this->billParseDate($this->billEndDate);

        return $start !== null && $end !== null && $end >= $start;
    }

    protected function buildBillRequest(): BillComparisonRequest
    {
        $total = $this->billTotalValue();

        // Normalize to Voltikka's comparable basis: energy only, incl. ALV 25.5 %.
        $comparable = $this->billIncludesVat ? $total : ($total * BillComparison::VAT_MULTIPLIER);

        return new BillComparisonRequest(
            startDate: Carbon::parse($this->billStartDate, 'Europe/Helsinki'),
            endDate: Carbon::parse($this->billEndDate, 'Europe/Helsinki'),
            kwh: $this->billKwhValue(),
            userTotalEur: $comparable,
            includesHeating: $this->billIncludesHeating,
            annualKwhOverride: null,
        );
    }

    /**
     * Period presets cover only fully completed billing months (the current
     * unbilled month is excluded), matching the standalone /maksatko-liikaa tool.
     */
    protected function applyBillPreset(): void
    {
        $today = Carbon::today('Europe/Helsinki');

        $start = match ($this->billPeriodPreset) {
            'last_month' => $today->copy()->subMonthNoOverflow()->startOfMonth(),
            'month_before' => $today->copy()->subMonthsNoOverflow(2)->startOfMonth(),
            'two_months_before' => $today->copy()->subMonthsNoOverflow(3)->startOfMonth(),
            default => null,
        };

        if ($start === null) {
            return; // custom — keep user-entered dates
        }

        $this->billStartDate = $start->toDateString();
        $this->billEndDate = $start->copy()->endOfMonth()->toDateString();
    }

    /**
     * @return array<string, string>
     */
    protected function computeBillPresetLabels(): array
    {
        $today = Carbon::today('Europe/Helsinki');

        return [
            'last_month' => $this->billMonthLabel($today->copy()->subMonthNoOverflow()->startOfMonth()),
            'month_before' => $this->billMonthLabel($today->copy()->subMonthsNoOverflow(2)->startOfMonth()),
            'two_months_before' => $this->billMonthLabel($today->copy()->subMonthsNoOverflow(3)->startOfMonth()),
            'custom' => 'Muu jakso',
        ];
    }

    protected function billMonthLabel(Carbon $date): string
    {
        $fiMonths = [
            1 => 'tammikuu', 2 => 'helmikuu', 3 => 'maaliskuu', 4 => 'huhtikuu',
            5 => 'toukokuu', 6 => 'kesäkuu', 7 => 'heinäkuu', 8 => 'elokuu',
            9 => 'syyskuu', 10 => 'lokakuu', 11 => 'marraskuu', 12 => 'joulukuu',
        ];

        return ucfirst($fiMonths[(int) $date->format('n')]).' '.$date->format('Y');
    }
}
