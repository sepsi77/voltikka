<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpotSocialPublication extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'content_date',
        'status',
        'attempt_count',
        'data_as_of',
        'started_at',
        'completed_at',
        'published_at',
        'postfast_video_key',
        'posted_count',
        'skipped_platforms',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'content_date' => 'date',
            'attempt_count' => 'integer',
            'data_as_of' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'published_at' => 'datetime',
            'posted_count' => 'integer',
            'skipped_platforms' => 'array',
        ];
    }
}
