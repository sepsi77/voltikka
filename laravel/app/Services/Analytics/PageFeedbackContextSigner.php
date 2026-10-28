<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Signs the page facts of the feedback widget: the page type and an optional subject
 * (contract ID or company slug). The browser cannot replace them, so a stored answer
 * always belongs to a page type that rendered the widget.
 */
final class PageFeedbackContextSigner
{
    public const VERSION = 1;

    /** Same window as the contract click context: it tolerates the stale edge HTML window. */
    public const LIFETIME_HOURS = 96;

    private const PURPOSE = 'voltikka-page-feedback-context-v1';

    private const EXPECTED_KEYS = ['version', 'page_type', 'subject_id', 'issued_at', 'expires_at'];

    public function __construct(
        private readonly AnalyticsContextToken $token,
    ) {}

    public function sign(string $pageType, ?string $subjectId = null, ?CarbonInterface $issuedAt = null): string
    {
        $issued = CarbonImmutable::instance($issuedAt ?? now())->utc();

        return $this->token->encode([
            'version' => self::VERSION,
            'page_type' => $pageType,
            'subject_id' => $subjectId,
            'issued_at' => $issued->timestamp,
            'expires_at' => $issued->addHours(self::LIFETIME_HOURS)->timestamp,
        ], self::PURPOSE);
    }

    /** @return array{page_type: string, subject_id: ?string} */
    public function verify(string $token): array
    {
        $payload = $this->token->decode($token, self::PURPOSE);

        if (array_keys($payload) !== self::EXPECTED_KEYS) {
            throw new InvalidSignedContext('The signed context schema is invalid.');
        }

        $subject = $payload['subject_id'];

        if ($payload['version'] !== self::VERSION
            || ! is_string($payload['page_type'])
            || ! PageFeedbackCatalog::isPageType($payload['page_type'])
            || ! ($subject === null || (is_string($subject) && $subject !== '' && mb_strlen($subject) <= 255))
            || ! is_int($payload['issued_at'])
            || ! is_int($payload['expires_at'])) {
            throw new InvalidSignedContext('The signed context fields are invalid.');
        }

        $now = CarbonImmutable::now('UTC')->timestamp;

        if ($payload['issued_at'] > ($now + 300)
            || $payload['expires_at'] <= $payload['issued_at']
            || $payload['expires_at'] <= $now
            || ($payload['expires_at'] - $payload['issued_at']) > (self::LIFETIME_HOURS * 3600)) {
            throw new InvalidSignedContext('The signed context time range is invalid.');
        }

        return ['page_type' => $payload['page_type'], 'subject_id' => $subject];
    }
}
