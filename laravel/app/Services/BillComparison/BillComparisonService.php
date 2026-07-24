<?php

namespace App\Services\BillComparison;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Models\SpotPriceHour;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\BillComparisonRequest;
use App\Services\DTO\BillComparisonResult;
use App\Services\DTO\BillComparisonRow;
use App\Services\DTO\EnergyUsage;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Compares a user's electricity bill against the current active market
 * contracts for the same billing period and consumption.
 *
 * The user's bill total IS their contract's true cost for the period, so we do
 * not need to model their pricing model, day/night split, or margin. We compute
 * every active household contract's cost for the same period + consumption and
 * slot the user's € total into the ranking by period cost.
 *
 * Two costs are produced per contract:
 *  - period cost: the exact-ish counterfactual for the bill's date range + kWh
 *    (spot uses actual historical hourly spot prices for the period).
 *  - annual cost: a seasonal-adjusted estimate via the existing
 *    `ContractPriceCalculator`, so the annualized "€/kk" savings shown to the
 *    user stays consistent with the rest of Voltikka's listings.
 *
 * Market contract costs are energy-only, including ALV 25.5 % (no siirto),
 * which is exactly the comparable to "the electricity portion of your bill
 * including taxes". The component assumes the request total is already
 * VAT-normalized to that basis.
 */
class BillComparisonService
{
    /**
     * Default day/night consumption split for time-of-use contracts when only
     * period-total kWh is known. Matches ContractPriceCalculator's default
     * night share (NIGHT_TIME_SHARES['default'] = 0.15).
     */
    private const DEFAULT_NIGHT_SHARE = 0.15;

    public function __construct(
        private readonly ContractPriceCalculator $calculator,
        private readonly ConsumptionProfile $profile,
        private readonly \App\Services\CanonicalPricing\CanonicalContractPricingService $canonicalPricing,
    ) {
    }

    public function compare(BillComparisonRequest $request): BillComparisonResult
    {
        $ctx = $this->periodContext($request);

        $warnings = $ctx['warnings'];
        $startLocal = $ctx['startLocal'];
        $endLocal = $ctx['endLocal'];
        $totalDays = $ctx['totalDays'];
        $monthsInPeriod = $ctx['monthsInPeriod'];
        $kwh = $ctx['kwh'];
        $userTotalEur = $ctx['userTotalEur'];
        $userImpliedCentsPerKwh = $ctx['userImpliedCentsPerKwh'];
        $annualKwh = $ctx['annualKwh'];
        $spotHours = $ctx['spotHours'];
        $spotAvgCentsPerKwh = $ctx['spotAvgCentsPerKwh'];
        $spotPriceDay = $ctx['spotPriceDay'];
        $spotPriceNight = $ctx['spotPriceNight'];

        $contracts = $this->loadActiveHouseholdContracts();
        $componentsByContractId = ElectricityContract::getLatestPriceComponentsForCalculationByContractIds(
            $contracts->pluck('id')
        );

        $rows = [];
        foreach ($contracts as $contract) {
            $components = $componentsByContractId[$contract->id] ?? [];
            $row = $this->buildMarketRow(
                $contract,
                $components,
                $kwh,
                $annualKwh,
                $totalDays,
                $monthsInPeriod,
                $startLocal,
                $endLocal,
                $spotHours,
                $spotPriceDay,
                $spotPriceNight,
            );
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        // User's own row. Period cost is the bill total (ground truth).
        $userAnnualCost = $kwh > 0 ? ($userTotalEur / $kwh) * $annualKwh : 0.0;
        $userRow = new BillComparisonRow(
            contractId: null,
            name: 'Sinun sopimuksesi',
            companyName: '',
            companySlug: null,
            pricingModel: null,
            contractType: null,
            periodCostEur: $userTotalEur,
            annualCostEur: $userAnnualCost,
            impliedCentsPerKwh: $userImpliedCentsPerKwh,
            isSpot: false,
            spotMargin: null,
            hasPromo: false,
            isUser: true,
            detailUrl: null,
            available: true,
        );
        $rows[] = $userRow;

        // Sort by period cost ascending; the user's bill is directly comparable
        // to every market contract's period cost (same range + consumption).
        usort($rows, fn (BillComparisonRow $a, BillComparisonRow $b) => $a->periodCostEur <=> $b->periodCostEur);

        $totalContracts = count($rows);
        $userRank = 0;
        foreach ($rows as $index => $row) {
            if ($row->isUser) {
                $userRank = $index + 1;
                break;
            }
        }

        $cheapest = $rows[0] ?? null;
        $cheapestAnnual = $cheapest?->annualCostEur;
        $periodSavingEur = $cheapest ? ($userTotalEur - $cheapest->periodCostEur) : 0.0;
        $annualSavingEur = ($cheapestAnnual !== null) ? ($userAnnualCost - $cheapestAnnual) : 0.0;
        $monthlySavingEur = $annualSavingEur / 12;

        $hasSpotInTop = false;
        foreach (array_slice($rows, 0, 3) as $topRow) {
            if ($topRow->isSpot) {
                $hasSpotInTop = true;
                break;
            }
        }

        return new BillComparisonResult(
            rows: $rows,
            userImpliedCentsPerKwh: $userImpliedCentsPerKwh,
            userAnnualCost: $userAnnualCost,
            userRank: $userRank,
            totalContracts: $totalContracts,
            periodSavingEur: $periodSavingEur,
            annualSavingEur: $annualSavingEur,
            monthlySavingEur: $monthlySavingEur,
            spotAvgCentsPerKwh: $spotAvgCentsPerKwh,
            hasSpotInTop: $hasSpotInTop,
            annualKwh: $annualKwh,
            warnings: $warnings,
        );
    }

    /**
     * Compute period-cost comparison rows for a specific set of contracts
     * (e.g. the current filtered listing) instead of the full active-household
     * universe. Rows are keyed by contract id; only available rows are returned
     * (spot contracts with no spot history for the period, or contracts with no
     * usable pricing, are omitted, matching compare()). Consumption-cap
     * eligibility is still applied against the annualized kWh.
     *
     * This is the in-listing ("Maksatko liikaa" on /sahkosopimus) entry point:
     * the caller owns filtering/sorting/pagination and only needs each
     * contract's exact-period counterfactual cost.
     *
     * @param  iterable<int, ElectricityContract>  $contracts
     * @return array{rows: array<int, BillComparisonRow>, annual_kwh: int, period_months: float, user_period_cost: float, spot_avg_cents_per_kwh: ?float}
     */
    public function periodRowsForContracts(iterable $contracts, BillComparisonRequest $request): array
    {
        $ctx = $this->periodContext($request);

        $contracts = $contracts instanceof Collection ? $contracts : collect($contracts);

        $componentsByContractId = ElectricityContract::getLatestPriceComponentsForCalculationByContractIds(
            $contracts->pluck('id')
        );

        $rows = [];
        foreach ($contracts as $contract) {
            $row = $this->buildMarketRow(
                $contract,
                $componentsByContractId[$contract->id] ?? [],
                $ctx['kwh'],
                $ctx['annualKwh'],
                $ctx['totalDays'],
                $ctx['monthsInPeriod'],
                $ctx['startLocal'],
                $ctx['endLocal'],
                $ctx['spotHours'],
                $ctx['spotPriceDay'],
                $ctx['spotPriceNight'],
            );

            if ($row !== null) {
                $rows[$contract->id] = $row;
            }
        }

        return [
            'rows' => $rows,
            'annual_kwh' => $ctx['annualKwh'],
            'period_months' => $ctx['monthsInPeriod'],
            'user_period_cost' => $ctx['userTotalEur'],
            'spot_avg_cents_per_kwh' => $ctx['spotAvgCentsPerKwh'],
        ];
    }

    /**
     * Shared per-request period setup (dates, annualized kWh, spot history and
     * trailing-365-day spot averages) used by both compare() and
     * periodRowsForContracts() so the two paths stay numerically identical.
     *
     * @return array<string, mixed>
     */
    private function periodContext(BillComparisonRequest $request): array
    {
        $warnings = [];

        $startLocal = Carbon::parse($request->startDate, 'Europe/Helsinki')->startOfDay();
        $endLocal = Carbon::parse($request->endDate, 'Europe/Helsinki')->endOfDay();
        $totalDays = $startLocal->startOfDay()->diffInDays($endLocal->copy()->startOfDay()) + 1;
        $monthsInPeriod = max(0.0, $totalDays / 30.0);

        $kwh = $request->kwh;
        $userTotalEur = $request->userTotalEur;
        $userImpliedCentsPerKwh = $kwh > 0 ? ($userTotalEur / $kwh) * 100 : 0.0;

        if ($userImpliedCentsPerKwh > 0 && ($userImpliedCentsPerKwh < 1 || $userImpliedCentsPerKwh > 50)) {
            $warnings[] = 'implied_out_of_range';
        }

        // Annualized consumption: use the visitor's known annual consumption
        // if provided, otherwise scale the period kWh up with the seasonal
        // profile.
        $periodShare = $this->profile->periodShare($startLocal, $endLocal, $request->includesHeating);
        $annualKwh = ($request->annualKwhOverride !== null && $request->annualKwhOverride > 0)
            ? (int) round($request->annualKwhOverride)
            : ($periodShare > 0 ? (int) round($kwh / $periodShare) : (int) round($kwh * 12));

        // Spot history for the billing period (lazily loaded, shared by all spot contracts).
        $spotHours = $this->loadSpotHours($startLocal, $endLocal);
        $spotAvgCentsPerKwh = $this->spotAverageCentsPerKwh($spotHours);

        // Trailing 365-day spot averages for the annualized estimate path,
        // matching how ContractsList computes spot contract annual costs.
        $rollingSpot = SpotPriceAverage::latestRolling365Days();

        return [
            'warnings' => $warnings,
            'startLocal' => $startLocal,
            'endLocal' => $endLocal,
            'totalDays' => $totalDays,
            'monthsInPeriod' => $monthsInPeriod,
            'kwh' => $kwh,
            'userTotalEur' => $userTotalEur,
            'userImpliedCentsPerKwh' => $userImpliedCentsPerKwh,
            'annualKwh' => $annualKwh,
            'spotHours' => $spotHours,
            'spotAvgCentsPerKwh' => $spotAvgCentsPerKwh,
            'spotPriceDay' => $rollingSpot?->day_avg_with_tax,
            'spotPriceNight' => $rollingSpot?->night_avg_with_tax,
        ];
    }

    /**
     * @return Collection<int, ElectricityContract>
     */
    private function loadActiveHouseholdContracts(): Collection
    {
        return ElectricityContract::query()
            ->active()
            ->whereIn('target_group', ['Household', 'Both'])
            ->with('company')
            ->get();
    }

    /**
     * @return Collection<int, SpotPriceHour>
     */
    private function loadSpotHours(CarbonInterface $startLocal, CarbonInterface $endLocal): Collection
    {
        $startUtc = Carbon::parse($startLocal, 'Europe/Helsinki')->utc();
        $endUtc = Carbon::parse($endLocal, 'Europe/Helsinki')->utc();

        return SpotPriceHour::forRegion('FI')
            ->whereBetween('utc_datetime', [$startUtc, $endUtc])
            ->get();
    }

    /**
     * @param  Collection<int, SpotPriceHour>  $spotHours
     */
    private function spotAverageCentsPerKwh(Collection $spotHours): ?float
    {
        if ($spotHours->isEmpty()) {
            return null;
        }

        $sum = 0.0;
        $count = 0;
        foreach ($spotHours as $hour) {
            $sum += (float) $hour->price_with_tax;
            $count++;
        }

        return $count > 0 ? $sum / $count : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @param  Collection<int, SpotPriceHour>  $spotHours
     */
    private function buildMarketRow(
        ElectricityContract $contract,
        array $components,
        float $kwh,
        int $annualKwh,
        int $totalDays,
        float $monthsInPeriod,
        CarbonInterface $startLocal,
        CarbonInterface $endLocal,
        Collection $spotHours,
        ?float $spotPriceDay,
        ?float $spotPriceNight,
    ): ?BillComparisonRow {
        // Consumption-cap eligibility. Products with an annual kWh floor/cap
        // (e.g. flat-fee Helpposähkö tiers capped at 1200/2400/3600 kWh/y) are
        // only a real option when the visitor's annualized consumption fits.
        // Offering an out-of-band contract as the cheapest choice is wrong and,
        // for the capped flat-fee tiers, produces a consumption-immune row that
        // freezes the top of the ranking. `$annualKwh` already reflects the
        // visitor's known annual use or the seasonal annualization.
        if (! $this->fitsConsumptionLimits($contract, $annualKwh)) {
            return null;
        }

        // Keep bill comparison consistent with the listings: never rank a contract whose
        // canonical pricing excludes it from comparison (unknown-future promo, broken data).
        if ($this->canonicalPricing->enabled()
            && ! $this->canonicalPricing->evaluate($contract, new EnergyUsage(total: $annualKwh, basicLiving: $annualKwh))['outcome']->isListed()) {
            return null;
        }

        $rates = $this->extractRates($components);
        $isSpot = $this->isSpotContract($contract, $rates);

        $periodCost = null;

        if ($isSpot) {
            $periodCost = $this->spotPeriodCost(
                $rates['spotMargin'],
                $rates['monthlyFee'],
                $kwh,
                $monthsInPeriod,
                $spotHours
            );
        } elseif ($rates['generalRate'] > 0) {
            $periodCost = ($rates['generalRate'] * $kwh) / 100 + ($rates['monthlyFee'] * $monthsInPeriod);
        } elseif ($rates['dayTimeRate'] > 0 || $rates['nightTimeRate'] > 0) {
            $dayKwh = $kwh * (1 - self::DEFAULT_NIGHT_SHARE);
            $nightKwh = $kwh * self::DEFAULT_NIGHT_SHARE;
            $energyC = ($rates['dayTimeRate'] * $dayKwh) + ($rates['nightTimeRate'] * $nightKwh);
            $periodCost = ($energyC / 100) + ($rates['monthlyFee'] * $monthsInPeriod);
        } elseif ($rates['seasonalWinterDayRate'] > 0 || $rates['seasonalOtherRate'] > 0) {
            $periodCost = $this->seasonalPeriodCost(
                $rates['seasonalWinterDayRate'],
                $rates['seasonalOtherRate'],
                $rates['monthlyFee'],
                $kwh,
                $totalDays,
                $monthsInPeriod,
                $startLocal,
                $endLocal
            );
        } elseif ($rates['monthlyFee'] > 0) {
            // Flat monthly-fee product with no per-kWh energy rate (e.g. Helen
            // Helpposähkö tiers, where energy is included up to an annual cap).
            // The flat fee is the real cost, but only within the contract's
            // consumption cap, which is enforced by the eligibility check at the
            // top of this method. Without that cap this would sort to the top as
            // a misleading "cheapest" option that ignores consumption.
            $periodCost = $rates['monthlyFee'] * $monthsInPeriod;
        } else {
            // No usable pricing at all — skip rather than show a misleading €0.
            return null;
        }

        if ($periodCost === null) {
            return null;
        }

        // Annualized estimate via the standard site calculator (consistent with
        // contract listings: trailing-365-day spot, seasonal model, promos).
        $annualCost = $this->annualCost($contract, $components, $annualKwh, $spotPriceDay, $spotPriceNight);

        $impliedCents = $kwh > 0 ? ($periodCost / $kwh) * 100 : 0.0;

        $hasPromo = false;
        foreach ($components as $comp) {
            if (! empty($comp['has_discount']) && (float) ($comp['discount_value'] ?? 0) > 0) {
                $hasPromo = true;
                break;
            }
        }

        return new BillComparisonRow(
            contractId: $contract->id,
            name: $contract->name,
            companyName: $contract->company?->name ?? $contract->company_name,
            companySlug: $contract->company?->name_slug,
            pricingModel: $contract->pricing_model,
            contractType: $contract->contract_type,
            periodCostEur: $periodCost,
            annualCostEur: $annualCost,
            impliedCentsPerKwh: $impliedCents,
            isSpot: $isSpot,
            spotMargin: $isSpot ? ($rates['spotMargin'] > 0 ? $rates['spotMargin'] : null) : null,
            hasPromo: $hasPromo,
            isUser: false,
            detailUrl: route('contract.detail', $contract->id),
            available: true,
        );
    }

    /**
     * Whether the visitor's annualized consumption fits the contract's annual
     * kWh limits. Contracts without limits (null/0) are always eligible.
     */
    private function fitsConsumptionLimits(ElectricityContract $contract, int $annualKwh): bool
    {
        $max = $contract->consumption_limitation_max_x_kwh_per_y;
        $min = $contract->consumption_limitation_min_x_kwh_per_y;

        if ($max !== null && $max > 0 && $annualKwh > $max) {
            return false;
        }

        if ($min !== null && $min > 0 && $annualKwh < $min) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, float>  $rates
     */
    private function isSpotContract(ElectricityContract $contract, array $rates): bool
    {
        if ($contract->pricing_model === 'Spot') {
            return true;
        }

        // Mirror ContractPriceCalculator's heuristic: a very low general rate is
        // a margin added on top of spot prices.
        return $rates['generalRate'] > 0 && $rates['generalRate'] < 0.8;
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, float>
     */
    private function extractRates(array $components): array
    {
        $rates = [
            'monthlyFee' => 0.0,
            'generalRate' => 0.0,
            'dayTimeRate' => 0.0,
            'nightTimeRate' => 0.0,
            'seasonalWinterDayRate' => 0.0,
            'seasonalOtherRate' => 0.0,
            'spotMargin' => 0.0,
        ];

        foreach ($components as $comp) {
            $type = $comp['price_component_type'] ?? '';
            $price = (float) ($comp['price'] ?? 0);

            match ($type) {
                'Monthly' => $rates['monthlyFee'] = $price,
                'General' => $rates['generalRate'] = $price,
                'DayTime' => $rates['dayTimeRate'] = $price,
                'NightTime' => $rates['nightTimeRate'] = $price,
                'SeasonalWinterDay' => $rates['seasonalWinterDayRate'] = $price,
                'SeasonalOther' => $rates['seasonalOtherRate'] = $price,
                default => null,
            };
        }

        // Spot margin: first non-monthly component price (matches the calculator).
        if ($rates['generalRate'] > 0 && $rates['generalRate'] < 0.8) {
            $rates['spotMargin'] = $rates['generalRate'];
        } else {
            foreach ($components as $comp) {
                if (($comp['price_component_type'] ?? '') !== 'Monthly') {
                    $rates['spotMargin'] = (float) ($comp['price'] ?? 0);
                    break;
                }
            }
        }

        return $rates;
    }

    /**
     * @param  Collection<int, SpotPriceHour>  $spotHours
     */
    private function spotPeriodCost(
        float $margin,
        float $monthlyFee,
        float $kwh,
        float $monthsInPeriod,
        Collection $spotHours
    ): ?float {
        if ($spotHours->isEmpty()) {
            // No historical spot data for this period — cannot estimate.
            return null;
        }

        $hoursCount = $spotHours->count();
        $hourlyKwh = $kwh / $hoursCount;

        $spotEnergyEur = 0.0;
        foreach ($spotHours as $hour) {
            $spotEnergyEur += ($hourlyKwh * (float) $hour->price_with_tax) / 100;
        }

        $marginEur = ($margin * $kwh) / 100;
        $baseEur = $monthlyFee * $monthsInPeriod;

        return $spotEnergyEur + $marginEur + $baseEur;
    }

    private function seasonalPeriodCost(
        float $winterDayRate,
        float $otherRate,
        float $monthlyFee,
        float $kwh,
        int $totalDays,
        float $monthsInPeriod,
        CarbonInterface $startLocal,
        CarbonInterface $endLocal
    ): float {
        if ($totalDays <= 0) {
            $totalDays = 1;
        }

        [$winterDays, $otherDays] = $this->countWinterOtherDays($startLocal, $endLocal);

        $winterKwh = $kwh * ($winterDays / $totalDays);
        $otherKwh = $kwh * ($otherDays / $totalDays);

        // Winter: day/night split (SeasonalWinterDay for day, SeasonalOther for night).
        $winterEnergyC = $winterKwh * ((1 - self::DEFAULT_NIGHT_SHARE) * $winterDayRate + self::DEFAULT_NIGHT_SHARE * $otherRate);
        // Other seasons: SeasonalOther rate for all consumption.
        $otherEnergyC = $otherKwh * $otherRate;

        $energyEur = ($winterEnergyC + $otherEnergyC) / 100;
        $baseEur = $monthlyFee * $monthsInPeriod;

        return $energyEur + $baseEur;
    }

    /**
     * @return array{0: int, 1: int} [winterDays, otherDays]
     */
    private function countWinterOtherDays(CarbonInterface $startLocal, CarbonInterface $endLocal): array
    {
        $winter = 0;
        $other = 0;
        $cursor = Carbon::parse($startLocal, 'Europe/Helsinki')->startOfDay();
        $last = Carbon::parse($endLocal, 'Europe/Helsinki')->startOfDay();

        while ($cursor <= $last) {
            $month = (int) $cursor->format('n');
            if (in_array($month, [1, 2, 3, 11, 12], true)) {
                $winter++;
            } else {
                $other++;
            }
            $cursor = $cursor->copy()->addDay();
        }

        return [$winter, $other];
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     */
    private function annualCost(
        ElectricityContract $contract,
        array $components,
        int $annualKwh,
        ?float $spotPriceDay,
        ?float $spotPriceNight
    ): ?float {
        if ($annualKwh <= 0) {
            return null;
        }

        $usage = new EnergyUsage(total: $annualKwh, basicLiving: $annualKwh);

        if ($this->canonicalPricing->enabled()) {
            return $this->canonicalPricing->evaluate($contract, $usage)['outcome']->totalCost;
        }

        $contractData = [
            'contract_type' => $contract->contract_type,
            'pricing_model' => $contract->pricing_model,
            'metering' => $contract->metering,
        ];

        $result = $this->calculator->calculate($components, $contractData, $usage, $spotPriceDay, $spotPriceNight);

        return $result->totalCost;
    }

}

