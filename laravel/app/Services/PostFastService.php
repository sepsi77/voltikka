<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PostFastService
{
    private const BASE_URL = 'https://api.postfa.st';

    /**
     * Platform mapping from our LLM output keys to PostFast platform names.
     */
    private const PLATFORM_MAP = [
        'twitter' => 'X',
        'tiktok' => 'TIKTOK',
        'instagram' => 'INSTAGRAM',
        'youtube' => 'YOUTUBE',
    ];

    private ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.postfast.api_key');
    }

    /**
     * Check if the service is configured with an API key.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Get a presigned URL for uploading a video to PostFast's S3.
     *
     * @return array{url: string, key: string}
     * @throws \RuntimeException
     */
    public function getSignedUploadUrl(string $contentType = 'video/mp4'): array
    {
        $response = $this->request('POST', '/file/get-signed-upload-urls', [
            'contentType' => $contentType,
            'count' => 1,
        ]);

        if (!isset($response[0]['signedUrl'], $response[0]['key'])) {
            throw new \RuntimeException('Invalid response from PostFast upload URL endpoint: ' . json_encode($response));
        }

        return [
            'url' => $response[0]['signedUrl'],
            'key' => $response[0]['key'],
        ];
    }

    /**
     * Upload a video file to PostFast's S3 using a presigned URL.
     *
     * @param string $filePath Path to the local video file
     * @return string The PostFast file key for use in posts
     * @throws \RuntimeException
     */
    public function uploadVideo(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Video file not found: {$filePath}");
        }

        // Get presigned URL
        $upload = $this->getSignedUploadUrl('video/mp4');

        Log::info('PostFast: Got presigned upload URL', ['key' => $upload['key']]);

        // Upload file to presigned URL
        $fileContents = file_get_contents($filePath);
        $response = Http::withHeaders([
            'Content-Type' => 'video/mp4',
        ])->withBody($fileContents, 'video/mp4')
            ->put($upload['url']);

        if (!$response->successful()) {
            Log::error('PostFast: Video upload failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to upload video to PostFast S3: ' . $response->status());
        }

        Log::info('PostFast: Video uploaded successfully', ['key' => $upload['key']]);

        return $upload['key'];
    }

    /**
     * Get all connected social media accounts.
     *
     * @return array Array of accounts with id, platform, name
     */
    public function getSocialAccounts(): array
    {
        return $this->request('GET', '/social-media/my-social-accounts');
    }

    /**
     * Get connected accounts grouped by platform for easy lookup.
     *
     * @return array<string, array> e.g. ['X' => [...], 'TIKTOK' => [...]]
     */
    public function getAccountsGroupedByPlatform(): array
    {
        $accounts = $this->getSocialAccounts();
        $grouped = [];

        foreach ($accounts as $account) {
            $platform = $account['platform'] ?? null;
            if ($platform) {
                if (!isset($grouped[$platform])) {
                    $grouped[$platform] = [];
                }
                $grouped[$platform][] = $account;
            }
        }

        return $grouped;
    }

    /**
     * Schedule posts to connected social media platforms.
     *
     * @param string $videoKey The PostFast file key from uploadVideo()
     * @param array $texts Platform-specific texts ['twitter' => '...', 'tiktok' => '...', ...]
     * @param Carbon $scheduledAt When to publish the posts
     * @param bool $asDraft If true, create as draft instead of scheduled (for testing)
     * @return array Response from PostFast API
     */
    public function schedulePosts(string $videoKey, array $texts, Carbon $scheduledAt, bool $asDraft = false): array
    {
        // Get connected accounts
        $accountsByPlatform = $this->getAccountsGroupedByPlatform();

        Log::info('PostFast: Connected platforms', [
            'platforms' => array_keys($accountsByPlatform),
        ]);

        $posts = [];
        $skippedPlatforms = [];

        foreach ($texts as $llmKey => $text) {
            // Map our key to PostFast platform
            $platform = self::PLATFORM_MAP[$llmKey] ?? null;
            if (!$platform) {
                Log::warning("PostFast: Unknown platform key: {$llmKey}");
                continue;
            }

            // Find account for this platform
            if (!isset($accountsByPlatform[$platform]) || empty($accountsByPlatform[$platform])) {
                $skippedPlatforms[] = $platform;
                Log::warning("PostFast: No connected account for platform: {$platform}");
                continue;
            }

            // Use the first connected account for this platform
            $account = $accountsByPlatform[$platform][0];

            $post = [
                'socialMediaId' => $account['id'],
                'content' => $text,
                'mediaItems' => [
                    [
                        'key' => $videoKey,
                        'type' => 'VIDEO',
                        'sortOrder' => 0,
                    ],
                ],
            ];

            // Add scheduledAt to each post if not draft mode
            if (!$asDraft) {
                $post['scheduledAt'] = $scheduledAt->toIso8601String();
            }

            $posts[] = $post;

            Log::info("PostFast: Prepared post for {$platform}", [
                'account_name' => $account['name'] ?? 'unknown',
                'text_length' => mb_strlen($text),
            ]);
        }

        if (empty($posts)) {
            throw new \RuntimeException('No posts to schedule - no connected accounts found');
        }

        // Build platform-specific controls
        $controls = $this->buildPlatformControls($accountsByPlatform);

        // Build the payload
        $payload = [
            'posts' => $posts,
            ...$controls,
        ];

        // Set status: DRAFT for testing, SCHEDULED for production
        if ($asDraft) {
            $payload['status'] = 'DRAFT';
            Log::info('PostFast: Creating draft posts (test mode)', [
                'post_count' => count($posts),
                'skipped_platforms' => $skippedPlatforms,
            ]);
        } else {
            $payload['status'] = 'SCHEDULED';
            // Note: scheduledAt is set per-post, not at the top level
            Log::info('PostFast: Scheduling posts', [
                'post_count' => count($posts),
                'scheduled_at' => $scheduledAt->toIso8601String(),
                'skipped_platforms' => $skippedPlatforms,
            ]);
        }

        $response = $this->request('POST', '/social-posts', $payload);

        Log::info('PostFast: Posts created successfully', [
            'status' => $asDraft ? 'DRAFT' : 'SCHEDULED',
            'response' => $response,
        ]);

        return [
            'response' => $response,
            'posted_count' => count($posts),
            'skipped_platforms' => $skippedPlatforms,
            'is_draft' => $asDraft,
        ];
    }

    /**
     * Build platform-specific controls for the post request.
     */
    private function buildPlatformControls(array $accountsByPlatform): array
    {
        $controls = [];

        // TikTok controls
        if (isset($accountsByPlatform['TIKTOK'])) {
            $controls['tiktokPrivacy'] = 'PUBLIC';
            $controls['tiktokAllowComments'] = true;
        }

        // Instagram controls - post as Reel
        if (isset($accountsByPlatform['INSTAGRAM'])) {
            $controls['instagramPublishType'] = 'REEL';
        }

        // YouTube controls - post as Short
        if (isset($accountsByPlatform['YOUTUBE'])) {
            $controls['youtubeIsShort'] = true;
        }

        return $controls;
    }

    /**
     * Make an authenticated request to the PostFast API.
     *
     * @throws \RuntimeException
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('PostFast API key not configured');
        }

        $url = self::BASE_URL . $endpoint;

        $request = Http::withHeaders([
            'pf-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(60);

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url, $data),
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        if (!$response->successful()) {
            Log::error("PostFast API error: {$method} {$endpoint}", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException(
                "PostFast API request failed: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json() ?? [];
    }
}
