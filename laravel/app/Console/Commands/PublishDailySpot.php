<?php

namespace App\Console\Commands;

use App\Models\SpotSocialPublication;
use App\Services\PostFastService;
use App\Services\SocialMediaPromptFormatter;
use App\Services\SpotPriceVideoService;
use App\Services\SpotSocial\SpotSocialPublicationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class PublishDailySpot extends Command
{
    protected $signature = 'social:publish-daily-spot
                            {--skip-render : Skip video rendering (use existing video)}
                            {--skip-post : Skip social media posting}
                            {--draft : Create posts as drafts in PostFast (for testing)}
                            {--dry-run : Show what would be done without executing}
                            {--date= : Helsinki content date in YYYY-MM-DD format}
                            {--retry : Retry a failed or stale processing publication}';

    protected $description = 'Render daily spot price video, generate social media text, and post via PostFast';

    private const COMPOSITION_ID = 'DailySpotPrice';

    /**
     * Deterministic opening lines based on day rating.
     */
    private const DAY_RATING_OPENINGS = [
        'very_cheap' => 'Pörssisähkö on tänään HALPAA!',
        'cheap' => 'Pörssisähkö on tänään EDULLISTA!',
        'normal' => 'Pörssisähkö on tänään NORMAALIA!',
        'expensive' => 'Pörssisähkö on tänään KALLISTA!',
        'very_expensive' => 'Pörssisähkö on tänään KALLISTA!',
    ];

    /**
     * Hashtags to append to all social media posts.
     */
    private const HASHTAGS = '#pörssisähkö #sähkö #sähkönhinta #spothinnat #voltikka';

    private function getRemotionPath(): string
    {
        return config('services.remotion.path', '/app/remotion');
    }

    private function getOutputDir(): string
    {
        return config('services.remotion.output_dir', '/app/storage/app/videos');
    }

    public function __construct(
        private SpotPriceVideoService $videoService,
        private SocialMediaPromptFormatter $promptFormatter,
        private PostFastService $postFastService,
        private SpotSocialPublicationService $publicationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $skipPost = (bool) $this->option('skip-post');
        $asDraft = (bool) $this->option('draft');
        $isRetry = (bool) $this->option('retry');
        $dateOption = $this->option('date');

        if ($isRetry && ($isDryRun || $skipPost || $asDraft)) {
            $this->error('--retry cannot be used with --dry-run, --skip-post, or --draft.');

            return Command::FAILURE;
        }

        if ($isRetry && empty($dateOption)) {
            $this->error('--retry requires --date=YYYY-MM-DD.');

            return Command::FAILURE;
        }

        $contentDate = $this->parseContentDate($dateOption);
        if ($contentDate === null) {
            return Command::FAILURE;
        }

        $dateStr = $contentDate->format('Y-m-d');
        $this->info("Starting daily video pipeline for {$dateStr}");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $callsPostFast = ! $isDryRun && ! $skipPost;
        if (! $isDryRun && ($callsPostFast || $asDraft) && ! config('services.postfast.spot_social_publishing_enabled', false)) {
            $this->warn('Spot social publishing is disabled. Set SPOT_SOCIAL_PUBLISHING_ENABLED=true in production to enable it.');

            return Command::SUCCESS;
        }

        $readiness = $this->publicationService->readiness($contentDate);
        if (! $readiness->ready) {
            $this->info('Daily spot publication deferred. Complete hourly data is missing for: '.implode(', ', $readiness->incompleteDates));

            return Command::SUCCESS;
        }

        $usesLedger = ! $isDryRun && ! $skipPost && ! $asDraft;
        $publication = null;
        $dataAsOf = $this->dataAsOfForFirstAttempt($contentDate);

        if ($usesLedger) {
            $claim = $this->publicationService->claim($contentDate, $dataAsOf, $isRetry);
            if (! $claim->claimed) {
                $this->info($this->claimSkipMessage($claim->reason));

                return Command::SUCCESS;
            }

            $publication = $claim->publication;
            $dataAsOf = $publication->data_as_of->copy()->setTimezone(SpotSocialPublicationService::TIMEZONE);
        }

        try {
            $videoPath = null;
            if (! $this->option('skip-render')) {
                $videoPath = $this->renderVideo($dateStr, $dataAsOf, $isDryRun);
                if ($videoPath === null && ! $isDryRun) {
                    throw new \RuntimeException('Video rendering failed.');
                }
            } else {
                $this->info('Skipping video render');
                $videoPath = $this->getOutputDir()."/daily-{$dateStr}.mp4";
            }

            $videoData = $this->videoService->getDailyVideoData($dataAsOf);
            $socialTexts = $this->generateSocialMediaText($isDryRun, $videoData);
            if ($socialTexts === null && ! $isDryRun) {
                $this->warn('Failed to generate social media text, using fallback');
                $socialTexts = $this->getFallbackSocialTexts($videoData);
            }

            $socialTexts = $this->prependDayRatingOpening($socialTexts, $videoData);
            $socialTexts = $this->appendHashtags($socialTexts);

            $this->info('Social media texts:');
            foreach ($socialTexts as $platform => $text) {
                $length = mb_strlen($text);
                $this->line("  [{$platform}] ({$length} chars): {$text}");
            }

            $postResult = null;
            if (! $skipPost && $videoPath && $socialTexts) {
                $postResult = $this->postToSocialMedia($videoPath, $socialTexts, $isDryRun, $asDraft);
            } else {
                $this->info('Skipping social media posting');
            }

            if ($publication !== null && $postResult !== null) {
                $markedPublished = $this->publicationService->markPublished(
                    $publication,
                    $postResult['video_key'],
                    $postResult['posted_count'],
                    $postResult['skipped_platforms'],
                );

                if (! $markedPublished) {
                    $this->warn('Publication result was not stored because a newer attempt owns the ledger row. The newer state was not changed.');
                    Log::warning('Daily spot publication result lost its ledger claim', [
                        'date' => $dateStr,
                        'attempt_count' => $publication->attempt_count,
                    ]);
                }
            }

            $this->info('Daily video pipeline completed successfully!');
            Log::info('Daily video pipeline completed', [
                'date' => $dateStr,
                'data_as_of' => $dataAsOf->toIso8601String(),
                'video_path' => $videoPath,
                'social_texts' => $socialTexts,
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if ($publication !== null) {
                $markedFailed = $this->publicationService->markFailed($publication, $e->getMessage());

                if (! $markedFailed) {
                    $this->warn('Failure was not stored because a newer attempt owns the ledger row. The newer state was not changed.');
                }
            }

            $this->error('Pipeline failed: '.$e->getMessage());
            Log::error('Daily video pipeline failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    private function parseContentDate(mixed $dateOption): ?Carbon
    {
        if ($dateOption === null || $dateOption === '') {
            return Carbon::today(SpotSocialPublicationService::TIMEZONE);
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', (string) $dateOption, SpotSocialPublicationService::TIMEZONE);
        } catch (\Throwable) {
            $date = null;
        }

        if ($date === null || $date->format('Y-m-d') !== $dateOption) {
            $this->error('Invalid --date. Use YYYY-MM-DD.');

            return null;
        }

        return $date->startOfDay();
    }

    private function dataAsOfForFirstAttempt(Carbon $contentDate): Carbon
    {
        $now = Carbon::now(SpotSocialPublicationService::TIMEZONE);

        return $contentDate->copy()->setTime(
            $now->hour,
            $now->minute,
            $now->second,
            $now->microsecond,
        );
    }

    private function claimSkipMessage(string $reason): string
    {
        return match ($reason) {
            SpotSocialPublication::STATUS_PUBLISHED => 'Daily spot publication skipped. This content date is already published and cannot be retried.',
            SpotSocialPublication::STATUS_FAILED => 'Daily spot publication skipped. The prior attempt failed. Inspect PostFast, then use --retry --date=YYYY-MM-DD.',
            SpotSocialPublication::STATUS_PROCESSING => 'Daily spot publication skipped. Another attempt is processing or is not stale.',
            'not_found' => 'Daily spot publication retry skipped. No publication row exists for this content date.',
            default => "Daily spot publication skipped ({$reason}).",
        };
    }

    private function renderVideo(string $dateStr, Carbon $dataAsOf, bool $isDryRun): ?string
    {
        $this->info('Step 1: Rendering video...');

        $outputPath = $this->getOutputDir()."/daily-{$dateStr}.mp4";

        if ($isDryRun) {
            $this->line("Would render video to: {$outputPath}");

            return $outputPath;
        }

        // Ensure output directory exists
        if (! is_dir($this->getOutputDir())) {
            mkdir($this->getOutputDir(), 0755, true);
        }

        // Get API URL for Remotion to fetch data
        $apiUrl = config('app.url');

        $this->line("Using API URL: {$apiUrl}");
        $this->line("Output path: {$outputPath}");

        // Run Remotion render command using local binary
        $remotionBin = $this->getRemotionPath().'/node_modules/.bin/remotion';

        $result = Process::path($this->getRemotionPath())
            ->timeout(600) // 10 minutes timeout
            ->env([
                'VOLTIKKA_API_URL' => $apiUrl,
                'VOLTIKKA_VIDEO_AS_OF' => $dataAsOf->toIso8601String(),
                'PUPPETEER_EXECUTABLE_PATH' => env('PUPPETEER_EXECUTABLE_PATH', '/usr/bin/chromium'),
            ])
            ->run([
                $remotionBin,
                'render',
                'src/index.ts',
                self::COMPOSITION_ID,
                $outputPath,
                '--log=verbose',
            ]);

        if (! $result->successful()) {
            $this->error('Video rendering failed:');
            $this->line($result->errorOutput());
            Log::error('Remotion render failed', [
                'exit_code' => $result->exitCode(),
                'output' => $result->output(),
                'error' => $result->errorOutput(),
            ]);

            return null;
        }

        $this->info("Video rendered successfully: {$outputPath}");

        return $outputPath;
    }

    private const MAX_LLM_RETRIES = 3;

    private function generateSocialMediaText(bool $isDryRun, array $videoData): ?array
    {
        $this->info('Step 2: Generating social media text...');

        // Format the prompt
        $prompt = $this->promptFormatter->formatPrompt($videoData);

        if ($isDryRun) {
            $this->line('Would call LLM with prompt (showing first 500 chars):');
            $this->line(str_repeat('-', 60));
            $this->line(mb_substr($prompt, 0, 500).'...');
            $this->line(str_repeat('-', 60));

            return $this->getFallbackSocialTexts($videoData);
        }

        // Check if OpenRouter API key is configured
        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            $this->warn('OpenRouter API key not configured, using fallback text');

            return null;
        }

        $model = config('services.openrouter.default_model', 'anthropic/claude-sonnet-4');

        // Retry loop for LLM calls
        for ($attempt = 1; $attempt <= self::MAX_LLM_RETRIES; $attempt++) {
            $this->line("LLM attempt {$attempt}/".self::MAX_LLM_RETRIES." (model: {$model})");

            $result = $this->callLlmApi($prompt, $apiKey, $model);

            if ($result !== null) {
                return $result;
            }

            if ($attempt < self::MAX_LLM_RETRIES) {
                $this->warn("Attempt {$attempt} failed, retrying...");
                sleep(1); // Brief delay before retry
            }
        }

        $this->warn('All {self::MAX_LLM_RETRIES} LLM attempts failed');

        return null;
    }

    private function callLlmApi(string $prompt, string $apiKey, string $model): ?array
    {
        $baseUrl = config('services.openrouter.base_url', 'https://openrouter.ai/api/v1');

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url', 'https://voltikka.fi'),
                'X-Title' => 'Voltikka Daily Spot Price',
            ])->timeout(30)->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'max_tokens' => 500,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

            if (! $response->successful()) {
                $this->warn('LLM API call failed: '.$response->body());
                Log::error('OpenRouter API call failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $content = $response->json('choices.0.message.content');
            $content = trim($content);

            // Log usage for cost tracking
            $usage = $response->json('usage');
            if ($usage) {
                Log::info('OpenRouter API usage', [
                    'model' => $model,
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                ]);
            }

            // Parse and validate JSON response
            $parsed = $this->parseAndValidateResponse($content);
            if ($parsed === null) {
                $this->warn('Failed to parse LLM response as valid JSON');
                $this->line('Raw response: '.mb_substr($content, 0, 200));

                return null;
            }

            return $parsed;
        } catch (\Exception $e) {
            $this->warn('LLM API exception: '.$e->getMessage());
            Log::error('OpenRouter API exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function parseAndValidateResponse(string $content): ?array
    {
        // Try to extract JSON from the response (in case LLM added extra text)
        $jsonContent = $content;

        // If response contains markdown code blocks, extract the JSON
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $content, $matches)) {
            $jsonContent = $matches[1];
        } elseif (preg_match('/(\{[\s\S]*\})/', $content, $matches)) {
            // Try to find JSON object in the response
            $jsonContent = $matches[1];
        }

        $parsed = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Failed to parse LLM response as JSON', [
                'error' => json_last_error_msg(),
                'content' => $content,
            ]);

            return null;
        }

        // Validate required fields
        $requiredFields = ['twitter', 'tiktok', 'instagram', 'youtube'];
        $validatedResponse = [];

        foreach ($requiredFields as $field) {
            if (! isset($parsed[$field]) || ! is_string($parsed[$field])) {
                Log::warning("Missing or invalid field in LLM response: {$field}", [
                    'parsed' => $parsed,
                ]);
                // Use empty string as fallback for missing field
                $validatedResponse[$field] = '';
            } else {
                $validatedResponse[$field] = trim($parsed[$field]);
            }
        }

        // Log character counts
        foreach ($validatedResponse as $platform => $text) {
            $length = mb_strlen($text);
            $maxLength = in_array($platform, ['tiktok', 'youtube']) ? 150 : 180;
            if ($length > $maxLength) {
                Log::warning('Platform text exceeds limit', [
                    'platform' => $platform,
                    'length' => $length,
                    'max' => $maxLength,
                    'text' => $text,
                ]);
            }
        }

        return $validatedResponse;
    }

    private function getFallbackSocialTexts(array $videoData): array
    {
        $stats = $videoData['statistics'];
        $cheapestHour = $stats['cheapest_hour']['label'] ?? '-';
        $cheapestPrice = $stats['cheapest_hour']['price'] ?? '-';

        // Simple fallback tip for all platforms
        $tip = sprintf(
            'Halvin tunti tänään: %s (%s c/kWh) – ajoita isommat kulutukset sinne.',
            $cheapestHour,
            $cheapestPrice
        );

        return [
            'twitter' => $tip,
            'tiktok' => sprintf('Halvin tunti: %s – kuluta silloin!', $cheapestHour),
            'instagram' => $tip,
            'youtube' => sprintf('Halvin tunti %s – isot kuluttajat sinne.', $cheapestHour),
        ];
    }

    /**
     * Prepend deterministic opening line based on day rating.
     */
    private function prependDayRatingOpening(array $texts, array $videoData): array
    {
        $dayRatingCode = $videoData['comparison']['day_rating']['code'] ?? 'unknown';
        $opening = self::DAY_RATING_OPENINGS[$dayRatingCode] ?? null;

        if ($opening === null) {
            return $texts;
        }

        foreach ($texts as $platform => $text) {
            $texts[$platform] = $opening.' '.$text;
        }

        return $texts;
    }

    /**
     * Append hashtags to all social media posts.
     */
    private function appendHashtags(array $texts): array
    {
        foreach ($texts as $platform => $text) {
            $texts[$platform] = $text."\n\n".self::HASHTAGS;
        }

        return $texts;
    }

    /**
     * @return array{video_key: string, posted_count: int, skipped_platforms: list<string>}|null
     */
    private function postToSocialMedia(string $videoPath, array $texts, bool $isDryRun, bool $asDraft = false): ?array
    {
        $modeLabel = $asDraft ? 'as drafts' : 'scheduled';
        $this->info("Step 3: Uploading video and posting via PostFast ({$modeLabel})...");

        if ($isDryRun) {
            $this->line("Would upload video: {$videoPath}");
            $this->line("Would create posts ({$modeLabel}) to:");
            foreach ($texts as $platform => $text) {
                $this->line("  [{$platform}]: {$text}");
            }

            return null;
        }

        if (! $this->postFastService->isConfigured()) {
            throw new \RuntimeException('PostFast API is not configured.');
        }

        try {
            $this->line('Uploading video to PostFast...');
            $videoKey = $this->postFastService->uploadVideo($videoPath);
            $this->info("Video uploaded successfully (key: {$videoKey})");

            $scheduledAt = Carbon::now()->addMinute();

            if ($asDraft) {
                $this->line('Creating draft posts for review in PostFast dashboard...');
            } else {
                $this->line("Scheduling posts for: {$scheduledAt->toIso8601String()}");
            }

            $result = $this->postFastService->schedulePosts($videoKey, $texts, $scheduledAt, $asDraft);
            $postedCount = (int) ($result['posted_count'] ?? 0);
            $skippedPlatforms = array_values($result['skipped_platforms'] ?? []);

            if ($postedCount === 0) {
                throw new \RuntimeException('PostFast returned zero created posts.');
            }

            if ($asDraft) {
                $this->info('Draft posts created successfully!');
                $this->line('  Review them at https://postfa.st/dashboard');
            } else {
                $this->info('Posts scheduled successfully!');
            }
            $this->line("  Created for {$postedCount} platform(s)");

            if ($skippedPlatforms !== []) {
                $this->warn('  Skipped (not connected): '.implode(', ', $skippedPlatforms));
            }

            Log::info('Social media posts created via PostFast', [
                'video_key' => $videoKey,
                'status' => $asDraft ? 'DRAFT' : 'SCHEDULED',
                'scheduled_at' => $asDraft ? null : $scheduledAt->toIso8601String(),
                'posted_count' => $postedCount,
                'skipped_platforms' => $skippedPlatforms,
            ]);

            $this->deleteLocalVideo($videoPath);

            return [
                'video_key' => $videoKey,
                'posted_count' => $postedCount,
                'skipped_platforms' => $skippedPlatforms,
            ];
        } catch (\Throwable $e) {
            Log::error('PostFast posting failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \RuntimeException(
                'PostFast posting failed. Inspect PostFast before explicit retry because some external posts can already exist. Cause: '
                .$e->getMessage(),
                previous: $e,
            );
        }
    }

    private function deleteLocalVideo(string $videoPath): void
    {
        if (! file_exists($videoPath)) {
            return;
        }

        try {
            if (unlink($videoPath)) {
                $this->line("Local video file deleted: {$videoPath}");

                return;
            }

            $error = 'unlink returned false';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->warn("Could not delete local video file after successful PostFast publication: {$videoPath}");
        Log::warning('Daily spot local video cleanup failed after successful PostFast publication', [
            'video_path' => $videoPath,
            'error' => $error,
        ]);
    }
}
