<?php

namespace App\Services\Analytics;

use App\Models\PageFeedback;
use App\Services\ContractCard\Enums\PricingBucket;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Stores the "Löysitkö etsimäsi?" answers. One browser UUID is one row:
 *
 * - step `vote` creates the row (idempotent by UUID);
 * - step `reason` (after "En") and step `goal` each fill their columns once, within
 *   UPDATE_WINDOW_MINUTES of the vote, on the same page type;
 * - on a page without a vote (404) step `goal` creates the row.
 *
 * A late, repeated or out-of-order follow-up is a quiet no-op: the visitor already left
 * a stored answer, and a retry must not overwrite it.
 */
final class PageFeedbackHandler
{
    use NormalizesAnalyticsInput;

    public const TEXT_LENGTH = 500;

    public const UPDATE_WINDOW_MINUTES = 120;

    public function __construct(
        private readonly PageFeedbackContextSigner $contextSigner,
    ) {}

    /** @param array<string, mixed> $envelope */
    public function handle(array $envelope): void
    {
        $validated = Validator::make($envelope, [
            'event_uuid' => ['required', 'uuid'],
            'context' => ['required', 'string', 'max:2048'],
            'attribution' => ['required', 'array:source,medium,campaign,landing_path'],
            'attribution.source' => ['required', 'string', 'max:2048'],
            'attribution.medium' => ['required', 'string', 'max:2048'],
            'attribution.campaign' => ['present', 'nullable', 'string', 'max:2048'],
            'attribution.landing_path' => ['required', 'string', 'max:2048'],
            'page_path' => ['required', 'string', 'max:2048'],
            'device' => ['required', Rule::in(['mobile', 'desktop'])],
            'step' => ['required', Rule::in(['vote', 'reason', 'goal'])],
            'vote' => ['required_if:step,vote', 'nullable', Rule::in(['up', 'down'])],
            'choice' => ['nullable', 'string', 'max:48'],
            'position' => ['nullable', 'required_with:choice', 'integer', 'min:1', 'max:20'],
            'text' => ['nullable', 'string', 'max:'.self::TEXT_LENGTH],
            'facts' => ['nullable', 'array:consumption_kwh,pricing_filter,result_count,postcode_selected,bill_mode,listing_page'],
            'facts.consumption_kwh' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'facts.pricing_filter' => ['nullable', 'string', 'max:100'],
            'facts.result_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'facts.postcode_selected' => ['nullable', 'boolean'],
            'facts.bill_mode' => ['nullable', 'boolean'],
            'facts.listing_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ])->validate();

        try {
            $context = $this->contextSigner->verify($validated['context']);
        } catch (InvalidSignedContext $exception) {
            throw ValidationException::withMessages(['context' => $exception->getMessage()]);
        }

        $pageType = $context['page_type'];

        match ($validated['step']) {
            'vote' => $this->storeVote($validated, $context),
            'reason' => $this->storeFollowUp($validated, $pageType, 'reason', PageFeedbackCatalog::reasonsFor($pageType)),
            'goal' => PageFeedbackCatalog::hasVote($pageType)
                ? $this->storeFollowUp($validated, $pageType, 'goal', PageFeedbackCatalog::goalsFor($pageType))
                : $this->storeGoalOnly($validated, $context),
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array{page_type: string, subject_id: ?string}  $context
     */
    private function storeVote(array $validated, array $context): void
    {
        if (! PageFeedbackCatalog::hasVote($context['page_type'])) {
            throw ValidationException::withMessages(['step' => 'This page has no vote.']);
        }

        PageFeedback::query()->createOrFirst(
            ['event_uuid' => $validated['event_uuid']],
            $this->baseColumns($validated, $context) + ['vote' => $validated['vote']],
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array{page_type: string, subject_id: ?string}  $context
     */
    private function storeGoalOnly(array $validated, array $context): void
    {
        [$code, $position, $text] = $this->answer($validated, PageFeedbackCatalog::goalsFor($context['page_type']));

        PageFeedback::query()->createOrFirst(
            ['event_uuid' => $validated['event_uuid']],
            $this->baseColumns($validated, $context) + [
                'goal_code' => $code,
                'goal_position' => $position,
                'goal_text' => $text,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, string>  $choices
     */
    private function storeFollowUp(array $validated, string $pageType, string $field, array $choices): void
    {
        if ($field === 'reason' && $choices === []) {
            throw ValidationException::withMessages(['step' => 'This page has no reasons.']);
        }

        [$code, $position, $text] = $this->answer($validated, $choices);

        $query = PageFeedback::query()
            ->where('event_uuid', $validated['event_uuid'])
            ->where('page_type', $pageType)
            ->where('occurred_at', '>=', now()->utc()->subMinutes(self::UPDATE_WINDOW_MINUTES))
            ->whereNull($field.'_code')
            ->whereNull($field.'_text');

        if ($field === 'reason') {
            $query->where('vote', 'down');
        }

        $query->update([
            $field.'_code' => $code,
            $field.'_position' => $position,
            $field.'_text' => $text,
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, string>  $choices
     * @return array{0: ?string, 1: ?int, 2: ?string}
     */
    private function answer(array $validated, array $choices): array
    {
        $code = $validated['choice'] ?? null;
        $text = $this->normalizeFreeText($validated['text'] ?? null);

        if ($code !== null && ! array_key_exists($code, $choices)) {
            throw ValidationException::withMessages(['choice' => 'The choice is not offered on this page.']);
        }

        if ($code === null && $text === null) {
            throw ValidationException::withMessages(['choice' => 'A choice or a text is required.']);
        }

        if ($code !== null && $validated['position'] > count($choices)) {
            throw ValidationException::withMessages(['position' => 'The position is outside the shown list.']);
        }

        return [$code, $code === null ? null : (int) $validated['position'], $text];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array{page_type: string, subject_id: ?string}  $context
     * @return array<string, mixed>
     */
    private function baseColumns(array $validated, array $context): array
    {
        return [
            'occurred_at' => now()->utc(),
            'page_type' => $context['page_type'],
            'page_path' => $this->normalizePath($validated['page_path']),
            'subject_id' => $context['subject_id'],
            'device' => $validated['device'],
            ...$this->attributionColumns($validated['attribution']),
            ...$this->listingFacts($context['page_type'], $validated['facts'] ?? null),
        ];
    }

    /**
     * Listing facts are display state the browser reads from the list; they are not signed.
     * They are accepted only on listing pages and only in their closed shapes.
     *
     * @param  array<string, mixed>|null  $facts
     * @return array<string, mixed>
     */
    private function listingFacts(string $pageType, ?array $facts): array
    {
        if ($pageType !== 'listing' || $facts === null) {
            return [];
        }

        $buckets = array_values(array_filter(
            explode(',', (string) ($facts['pricing_filter'] ?? '')),
            fn (string $bucket): bool => PricingBucket::tryFrom($bucket) !== null,
        ));

        return [
            'consumption_kwh' => $facts['consumption_kwh'] ?? null,
            'pricing_filter' => $buckets === [] ? null : implode(',', array_unique($buckets)),
            'result_count' => $facts['result_count'] ?? null,
            'postcode_selected' => isset($facts['postcode_selected']) ? (bool) $facts['postcode_selected'] : null,
            'bill_mode' => isset($facts['bill_mode']) ? (bool) $facts['bill_mode'] : null,
            'listing_page' => $facts['listing_page'] ?? null,
        ];
    }

    private function normalizeFreeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = str_replace("\r\n", "\n", $value);
        $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', $text) ?? '';
        $text = trim(preg_replace('/[ ]{2,}/', ' ', $text) ?? '');

        return $text === '' ? null : Str::limit($text, self::TEXT_LENGTH, '');
    }
}
