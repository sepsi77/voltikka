<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One distinct third-party forecast feed as fetched (an append-only vintage).
 *
 * spot_price_forecasts holds only the latest value per hour for display. This table keeps each
 * distinct feed so that a later review can compare a forecast made before delivery with the
 * outcome. Rows are never updated; an identical feed (same hash) is not stored twice.
 *
 * first/last_utc_datetime are UTC text (like spot_prices_*.utc_datetime) and have no datetime
 * cast, because the app timezone can be Europe/Helsinki. Parse them with Carbon::parse($v, 'UTC').
 * The authoritative times are the unix seconds in `points`.
 */
class SpotPriceForecastRun extends Model
{
    protected $fillable = [
        'source',
        'region',
        'feed_hash',
        'fetched_at',
        'point_count',
        'first_utc_datetime',
        'last_utc_datetime',
        'points',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'point_count' => 'integer',
            'points' => 'array',
        ];
    }
}
