<?php

namespace App\Services\Analytics;

use Illuminate\Support\Str;

/** Shared attribution text and path normalization for the typed analytics handlers. */
trait NormalizesAnalyticsInput
{
    private const ATTRIBUTION_LENGTH = 100;

    private const CAMPAIGN_LENGTH = 150;

    private const PATH_LENGTH = 500;

    private function normalizeText(mixed $value, int $maximumLength, string $fallback = ''): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $normalized = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $normalized = Str::lower(Str::squish($normalized ?? ''));

        if ($normalized === '') {
            return $fallback;
        }

        return Str::limit($normalized, $maximumLength, '');
    }

    private function normalizePath(mixed $value): string
    {
        if (! is_string($value)) {
            return '/';
        }

        $path = preg_split('/[?#]/', $value, 2)[0] ?? '';
        $path = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($path)) ?? '';

        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }

        return Str::limit($path, self::PATH_LENGTH, '');
    }

    /**
     * @param  array{source: string, medium: string, campaign: ?string, landing_path: string}  $attribution
     * @return array{session_source: string, session_medium: string, session_campaign: ?string, landing_path: string}
     */
    private function attributionColumns(array $attribution): array
    {
        $campaign = $this->normalizeText($attribution['campaign'], self::CAMPAIGN_LENGTH);

        return [
            'session_source' => $this->normalizeText($attribution['source'], self::ATTRIBUTION_LENGTH, 'direct'),
            'session_medium' => $this->normalizeText($attribution['medium'], self::ATTRIBUTION_LENGTH, '(none)'),
            'session_campaign' => $campaign === '' ? null : $campaign,
            'landing_path' => $this->normalizePath($attribution['landing_path']),
        ];
    }
}
