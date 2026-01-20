<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CompanyLogoService
{
    private const LOGO_DIRECTORY = 'logos';
    private const TIMEOUT_SECONDS = 30;

    /**
     * Download and store a company's logo locally.
     *
     * @param Company $company The company whose logo to download
     * @return string|null The relative storage path if successful, null otherwise
     */
    public function downloadAndStore(Company $company): ?string
    {
        if (!$company->logo_url) {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->get($company->logo_url);

            if ($response->failed()) {
                Log::warning("Failed to download logo for {$company->name}", [
                    'url' => $company->logo_url,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $extension = $this->getExtensionFromResponse($response, $company->logo_url);
            $filename = $company->name_slug . '.' . $extension;
            $path = self::LOGO_DIRECTORY . '/' . $filename;

            Storage::disk('public')->put($path, $response->body());

            Log::info("Downloaded logo for {$company->name}", ['path' => $path]);

            return $path;
        } catch (\Exception $e) {
            Log::error("Error downloading logo for {$company->name}", [
                'url' => $company->logo_url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get the local storage path for a company's logo.
     *
     * @param Company $company The company to check
     * @return string|null The relative storage path if exists, null otherwise
     */
    public function getLocalPath(Company $company): ?string
    {
        foreach (['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'] as $extension) {
            $path = self::LOGO_DIRECTORY . '/' . $company->name_slug . '.' . $extension;
            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Check if a company has a locally stored logo.
     *
     * @param Company $company The company to check
     * @return bool True if local logo exists
     */
    public function hasLocalLogo(Company $company): bool
    {
        return $this->getLocalPath($company) !== null;
    }

    /**
     * Get the public URL for a locally stored logo.
     *
     * @param Company $company The company to get URL for
     * @return string|null The public URL if local logo exists, null otherwise
     */
    public function getPublicUrl(Company $company): ?string
    {
        $path = $this->getLocalPath($company);
        if ($path === null) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Determine file extension from response headers or URL.
     */
    private function getExtensionFromResponse(\Illuminate\Http\Client\Response $response, string $url): string
    {
        // Try to get from Content-Type header
        $contentType = $response->header('Content-Type');
        if ($contentType) {
            $mimeToExt = [
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/gif' => 'gif',
                'image/svg+xml' => 'svg',
                'image/webp' => 'webp',
            ];

            foreach ($mimeToExt as $mime => $ext) {
                if (str_contains($contentType, $mime)) {
                    return $ext;
                }
            }
        }

        // Fall back to URL extension
        $urlPath = parse_url($url, PHP_URL_PATH);
        if ($urlPath) {
            $ext = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'])) {
                return $ext;
            }
        }

        // Default to png
        return 'png';
    }
}
