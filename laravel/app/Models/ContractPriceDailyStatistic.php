<?php

namespace App\Models;

use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ContractPriceDailyStatistic extends Model
{
    public const UNIT_STATISTICS_METHOD_VERSION = 'unit_statistics_v1';

    protected $fillable = [
        'stat_date',
        'segment_key',
        'metric_key',
        'pricing_basis',
        'method_version',
        'calculation_basis',
        'estimate_basis',
        'compatibility_key',
        'basis_counts',
        'consumption_kwh',
        'min_value',
        'p20_value',
        'avg_value',
        'median_value',
        'p80_value',
        'max_value',
        'contract_count',
    ];

    protected static function booted(): void
    {
        static::saving(function (ContractPriceDailyStatistic $statistic): void {
            if ($statistic->method_version !== null) {
                return;
            }

            $statistic->method_version = $statistic->metric_key === 'annual_cost'
                ? AnnualCostMethodVersion::Legacy->value
                : self::UNIT_STATISTICS_METHOD_VERSION;
        });
    }

    public static function activeAnnualMethodVersion(): AnnualCostMethodVersion
    {
        return AnnualCostMethodVersion::from(
            (string) config(
                'contract_statistics.annual_cost.active_method_version',
                AnnualCostMethodVersion::Legacy->value,
            ),
        );
    }

    public function scopeUnitStatistics(Builder $query): Builder
    {
        return $query->where('method_version', self::UNIT_STATISTICS_METHOD_VERSION);
    }

    public function scopeAnnualCostByMethod(
        Builder $query,
        AnnualCostMethodVersion|string $methodVersion,
    ): Builder {
        return $query
            ->where('metric_key', 'annual_cost')
            ->where(
                'method_version',
                $methodVersion instanceof AnnualCostMethodVersion ? $methodVersion->value : $methodVersion,
            );
    }

    public function scopeActiveAnnualMethod(Builder $query): Builder
    {
        return $query->annualCostByMethod(self::activeAnnualMethodVersion());
    }

    public function scopeActiveMetricMethods(Builder $query): Builder
    {
        return $query->where(function (Builder $methods): void {
            $methods->unitStatistics()
                ->orWhere(function (Builder $annual): void {
                    $annual->activeAnnualMethod();
                });
        });
    }

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'consumption_kwh' => 'integer',
            'min_value' => 'float',
            'p20_value' => 'float',
            'avg_value' => 'float',
            'median_value' => 'float',
            'p80_value' => 'float',
            'max_value' => 'float',
            'contract_count' => 'integer',
            'basis_counts' => 'array',
        ];
    }
}
