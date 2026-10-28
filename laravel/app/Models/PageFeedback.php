<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class PageFeedback extends Model
{
    protected $table = 'page_feedback';

    protected $fillable = [
        'event_uuid',
        'occurred_at',
        'page_type',
        'page_path',
        'subject_id',
        'vote',
        'reason_code',
        'reason_position',
        'reason_text',
        'goal_code',
        'goal_position',
        'goal_text',
        'device',
        'consumption_kwh',
        'pricing_filter',
        'result_count',
        'postcode_selected',
        'bill_mode',
        'listing_page',
        'session_source',
        'session_medium',
        'session_campaign',
        'landing_path',
    ];

    /** UTC wall time, as in ContractOrderClick (see app/Services/Analytics/AGENTS.md). */
    protected function occurredAt(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): CarbonImmutable => CarbonImmutable::parse($value, 'UTC'),
            set: fn (DateTimeInterface|string $value): string => CarbonImmutable::parse($value, 'UTC')
                ->utc()
                ->format($this->getDateFormat()),
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason_position' => 'integer',
            'goal_position' => 'integer',
            'consumption_kwh' => 'integer',
            'result_count' => 'integer',
            'postcode_selected' => 'boolean',
            'bill_mode' => 'boolean',
            'listing_page' => 'integer',
        ];
    }
}
