<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpotPriceAverage extends Model
{
    /**
     * Period types.
     */
    public const PERIOD_DAILY = 'daily';
    public const PERIOD_MONTHLY = 'monthly';
    public const PERIOD_YEARLY = 'yearly';
    public const PERIOD_ROLLING_30D = 'rolling_30d';
    public const PERIOD_ROLLING_365D = 'rolling_365d';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'region',
        'period_type',
        'period_start',
        'period_end',
        'avg_price_without_tax',
        'avg_price_with_tax',
        'day_avg_without_tax',
        'day_avg_with_tax',
        'night_avg_without_tax',
        'night_avg_with_tax',
        'min_price_without_tax',
        'max_price_without_tax',
        'hours_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'avg_price_without_tax' => 'float',
            'avg_price_with_tax' => 'float',
            'day_avg_without_tax' => 'float',
            'day_avg_with_tax' => 'float',
            'night_avg_without_tax' => 'float',
            'night_avg_with_tax' => 'float',
            'min_price_without_tax' => 'float',
            'max_price_without_tax' => 'float',
            'hours_count' => 'integer',
        ];
    }

    /**
     * Scope to filter by region.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $region
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForRegion($query, string $region = 'FI')
    {
        return $query->where('region', $region);
    }

    /**
     * Scope to filter by period type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $periodType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string $periodType)
    {
        return $query->where('period_type', $periodType);
    }

    /**
     * Get the latest rolling 30-day average.
     *
     * @param string $region
     * @return self|null
     */
    public static function latestRolling30Days(string $region = 'FI'): ?self
    {
        return static::forRegion($region)
            ->ofType(self::PERIOD_ROLLING_30D)
            ->orderByDesc('period_start')
            ->first();
    }

    /**
     * Get the latest rolling 365-day average.
     *
     * @param string $region
     * @return self|null
     */
    public static function latestRolling365Days(string $region = 'FI'): ?self
    {
        return static::forRegion($region)
            ->ofType(self::PERIOD_ROLLING_365D)
            ->orderByDesc('period_start')
            ->first();
    }

    /**
     * Get the daily average for a specific date.
     *
     * @param string $date YYYY-MM-DD
     * @param string $region
     * @return self|null
     */
    public static function forDate(string $date, string $region = 'FI'): ?self
    {
        return static::forRegion($region)
            ->ofType(self::PERIOD_DAILY)
            ->where('period_start', $date)
            ->first();
    }

    /**
     * Get the monthly average for a specific month.
     *
     * @param string $month YYYY-MM
     * @param string $region
     * @return self|null
     */
    public static function forMonth(string $month, string $region = 'FI'): ?self
    {
        return static::forRegion($region)
            ->ofType(self::PERIOD_MONTHLY)
            ->where('period_start', $month . '-01')
            ->first();
    }

    /**
     * Get the yearly average for a specific year.
     *
     * @param int $year
     * @param string $region
     * @return self|null
     */
    public static function forYear(int $year, string $region = 'FI'): ?self
    {
        return static::forRegion($region)
            ->ofType(self::PERIOD_YEARLY)
            ->where('period_start', $year . '-01-01')
            ->first();
    }
}
