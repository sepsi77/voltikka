<?php

namespace App\Console\Commands;

use App\Services\PostFastService;
use App\Services\SocialMediaPromptFormatter;
use App\Services\SpotPriceVideoService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class RenderDailyVideo extends Command
{
    protected $signature = 'social:daily-video
                            {--skip-render : Skip video rendering (use existing video)}
                            {--skip-post : Skip social media posting}
                            {--draft : Create posts as drafts in PostFast (for testing)}
                            {--dry-run : Show what would be done without executing}';

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
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $helsinkiNow = Carbon::now('Europe/Helsinki');
        $dateStr = $helsinkiNow->format('Y-m-d');

        $this->info("Starting daily video pipeline for {$dateStr}");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        try {
            // Step 1: Render video
            $videoPath = null;
            if (!$this->option('skip-render')) {
                $videoPath = $this->renderVideo($dateStr, $isDryRun);
                if ($videoPath === null && !$isDryRun) {
                    return Command::FAILURE;
                }
            } else {
                $this->info('Skipping video render');
                $videoPath = $this->getOutputDir() . "/daily-{$dateStr}.mp4";
            }

            // Step 2: Generate social media text
            $videoData = $this->videoService->getDailyVideoData($helsinkiNow);
            $socialTexts = $this->generateSocialMediaText($isDryRun, $videoData);
            if ($socialTexts === null && !$isDryRun) {
                $this->warn('Failed to generate social media text, using fallback');
                $socialTexts = $this->getFallbackSocialTexts($helsinkiNow);
            }

            // Prepend deterministic opening based on day rating
            $socialTexts = $this->prependDayRatingOpening($socialTexts, $videoData);

            // Append hashtags
            $socialTexts = $this->appendHashtags($socialTexts);

            $this->info('Social media texts:');
            foreach ($socialTexts as $platform => $text) {
                $length = mb_strlen($text);
                $this->line("  [{$platform}] ({$length} chars): {$text}");
            }

            // Step 3: Upload video and post to social media via PostFast
            if (!$this->option('skip-post') && $videoPath && $socialTexts) {
                $asDraft = $this->option('draft');
                $this->postToSocialMedia($videoPath, $socialTexts, $isDryRun, $asDraft);
            } else {
                $this->info('Skipping social media posting');
            }

            $this->info('Daily video pipeline completed successfully!');
            Log::info('Daily video pipeline completed', [
                'date' => $dateStr,
                'video_path' => $videoPath,
                'social_texts' => $socialTexts,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Pipeline failed: ' . $e->getMessage());
            Log::error('Daily video pipeline failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }

    private function renderVideo(string $dateStr, bool $isDryRun): ?string
    {
        $this->info('Step 1: Rendering video...');

        $outputPath = $this->getOutputDir() . "/daily-{$dateStr}.mp4";

        if ($isDryRun) {
            $this->line("Would render video to: {$outputPath}");
            return $outputPath;
        }

        // Ensure output directory exists
        if (!is_dir($this->getOutputDir())) {
            mkdir($this->getOutputDir(), 0755, true);
        }

        // Get API URL for Remotion to fetch data
        $apiUrl = config('app.url');

        $this->line("Using API URL: {$apiUrl}");
        $this->line("Output path: {$outputPath}");

        // Run Remotion render command using local binary
        $remotionBin = $this->getRemotionPath() . '/node_modules/.bin/remotion';

        $result = Process::path($this->getRemotionPath())
            ->timeout(600) // 10 minutes timeout
            ->env([
                'VOLTIKKA_API_URL' => $apiUrl,
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

        if (!$result->successful()) {
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
            $this->line(mb_substr($prompt, 0, 500) . '...');
            $this->line(str_repeat('-', 60));
            return $this->getFallbackSocialTexts(Carbon::now('Europe/Helsinki'));
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
            $this->line("LLM attempt {$attempt}/" . self::MAX_LLM_RETRIES . " (model: {$model})");

            $result = $this->callLlmApi($prompt, $apiKey, $model);

            if ($result !== null) {
                return $result;
            }

            if ($attempt < self::MAX_LLM_RETRIES) {
                $this->warn("Attempt {$attempt} failed, retrying...");
                sleep(1); // Brief delay before retry
            }
        }

        $this->warn("All {self::MAX_LLM_RETRIES} LLM attempts failed");
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

            if (!$response->successful()) {
                $this->warn('LLM API call failed: ' . $response->body());
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
                $this->line('Raw response: ' . mb_substr($content, 0, 200));
                return null;
            }

            return $parsed;
        } catch (\Exception $e) {
            $this->warn('LLM API exception: ' . $e->getMessage());
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
            if (!isset($parsed[$field]) || !is_string($parsed[$field])) {
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
                Log::warning("Platform text exceeds limit", [
                    'platform' => $platform,
                    'length' => $length,
                    'max' => $maxLength,
                    'text' => $text,
                ]);
            }
        }

        return $validatedResponse;
    }

    private function getFallbackSocialTexts(Carbon $helsinkiNow): array
    {
        $videoData = $this->videoService->getDailyVideoData($helsinkiNow);
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
            $texts[$platform] = $opening . ' ' . $text;
        }

        return $texts;
    }

    /**
     * Append hashtags to all social media posts.
     */
    private function appendHashtags(array $texts): array
    {
        foreach ($texts as $platform => $text) {
            $texts[$platform] = $text . "\n\n" . self::HASHTAGS;
        }

        return $texts;
    }

    private function postToSocialMedia(string $videoPath, array $texts, bool $isDryRun, bool $asDraft = false): void
    {
        $modeLabel = $asDraft ? 'as drafts' : 'scheduled';
        $this->info("Step 3: Uploading video and posting via PostFast ({$modeLabel})...");

        if ($isDryRun) {
            $this->line("Would upload video: {$videoPath}");
            $this->line("Would create posts ({$modeLabel}) to:");
            foreach ($texts as $platform => $text) {
                $this->line("  [{$platform}]: {$text}");
            }
            return;
        }

        // Check if PostFast is configured
        if (!$this->postFastService->isConfigured()) {
            $this->warn('PostFast API not configured, skipping post');
            Log::warning('Social media posting skipped - PostFast API key not configured');
            return;
        }

        try {
            // Upload video to PostFast's S3
            $this->line('Uploading video to PostFast...');
            $videoKey = $this->postFastService->uploadVideo($videoPath);
            $this->info("Video uploaded successfully (key: {$videoKey})");

            // Schedule posts for immediate publishing (current time + 1 minute)
            $scheduledAt = Carbon::now()->addMinute();

            if ($asDraft) {
                $this->line("Creating draft posts for review in PostFast dashboard...");
            } else {
                $this->line("Scheduling posts for: {$scheduledAt->toIso8601String()}");
            }

            $result = $this->postFastService->schedulePosts($videoKey, $texts, $scheduledAt, $asDraft);

            if ($asDraft) {
                $this->info("Draft posts created successfully!");
                $this->line("  Review them at https://postfa.st/dashboard");
            } else {
                $this->info("Posts scheduled successfully!");
            }
            $this->line("  Created for {$result['posted_count']} platform(s)");

            if (!empty($result['skipped_platforms'])) {
                $this->warn("  Skipped (not connected): " . implode(', ', $result['skipped_platforms']));
            }

            Log::info('Social media posts created via PostFast', [
                'video_key' => $videoKey,
                'status' => $asDraft ? 'DRAFT' : 'SCHEDULED',
                'scheduled_at' => $asDraft ? null : $scheduledAt->toIso8601String(),
                'posted_count' => $result['posted_count'],
                'skipped_platforms' => $result['skipped_platforms'],
            ]);
        } catch (\Exception $e) {
            $this->error('PostFast posting failed: ' . $e->getMessage());
            Log::error('PostFast posting failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
