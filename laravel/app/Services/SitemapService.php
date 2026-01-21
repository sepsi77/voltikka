<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\Postcode;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class SitemapService
{
    /**
     * Housing types for SEO pages.
     */
    protected array $housingTypes = [
        'omakotitalo',
        'kerrostalo',
        'rivitalo',
    ];

    /**
     * Energy sources for SEO pages.
     */
    protected array $energySources = [
        'tuulisahko',
        'aurinkosahko',
        'vihrea-sahko',
    ];

    /**
     * Pricing types for SEO pages.
     */
    protected array $pricingTypes = [
        'porssisahko',
        'kiintea-hinta',
    ];

    /**
     * Get all URLs for the sitemap.
     */
    public function getAllUrls(): array
    {
        return array_merge(
            $this->getMainPageUrls(),
            $this->getPricingTypeUrls(),
            $this->getHousingTypeUrls(),
            $this->getEnergySourceUrls(),
            $this->getContractUrls(),
            $this->getCompanyUrls(),
            $this->getCityUrls(),
            $this->getMunicipalityUrls()
        );
    }

    /**
     * Get main page URLs with priority and changefreq.
     */
    public function getMainPageUrls(): array
    {
        $baseUrl = config('app.url');
        $today = Carbon::today()->toDateString();

        return [
            [
                'loc' => $baseUrl,
                'lastmod' => $today,
                'changefreq' => 'daily',
                'priority' => 1.0,
            ],
            [
                'loc' => $baseUrl . '/spot-price',
                'lastmod' => $today,
                'changefreq' => 'hourly',
                'priority' => 0.9,
            ],
            [
                'loc' => $baseUrl . '/sahkosopimus/paikkakunnat',
                'lastmod' => $today,
                'changefreq' => 'weekly',
                'priority' => 0.7,
            ],
            [
                'loc' => $baseUrl . '/sahkosopimus/laskuri',
                'lastmod' => $today,
                'changefreq' => 'monthly',
                'priority' => 0.6,
            ],
        ];
    }

    /**
     * Get pricing type SEO page URLs.
     */
    public function getPricingTypeUrls(): array
    {
        $baseUrl = config('app.url');
        $today = Carbon::today()->toDateString();

        return array_map(function ($type) use ($baseUrl, $today) {
            return [
                'loc' => $baseUrl . '/sahkosopimus/' . $type,
                'lastmod' => $today,
                'changefreq' => 'daily',
                'priority' => 0.9,
            ];
        }, $this->pricingTypes);
    }

    /**
     * Get housing type SEO page URLs.
     */
    public function getHousingTypeUrls(): array
    {
        $baseUrl = config('app.url');
        $today = Carbon::today()->toDateString();

        return array_map(function ($type) use ($baseUrl, $today) {
            return [
                'loc' => $baseUrl . '/sahkosopimus/' . $type,
                'lastmod' => $today,
                'changefreq' => 'weekly',
                'priority' => 0.8,
            ];
        }, $this->housingTypes);
    }

    /**
     * Get energy source SEO page URLs.
     */
    public function getEnergySourceUrls(): array
    {
        $baseUrl = config('app.url');
        $today = Carbon::today()->toDateString();

        return array_map(function ($source) use ($baseUrl, $today) {
            return [
                'loc' => $baseUrl . '/sahkosopimus/' . $source,
                'lastmod' => $today,
                'changefreq' => 'weekly',
                'priority' => 0.8,
            ];
        }, $this->energySources);
    }

    /**
     * Get individual contract page URLs.
     */
    public function getContractUrls(): array
    {
        $baseUrl = config('app.url');
        $today = Carbon::today()->toDateString();

        $contracts = ElectricityContract::select('id')
            ->pluck('id')
            ->toArray();

        return array_map(function ($contractId) use ($baseUrl, $today) {
            return [
                'loc' => $baseUrl . '/sahkosopimus/sopimus/' . $contractId,
                'lastmod' => $today,
                'changefreq' => 'weekly',
                'priority' => 0.7,
            ];
        }, $contracts);
    }

    /**
     * Get company page URLs.
     */
    public function getCompanyUrls(): array
    {
        $baseUrl = config('app.url');
        $today = Carbon::today()->toDateString();

        $companies = Company::select('name_slug')
            ->whereNotNull('name_slug')
            ->pluck('name_slug')
            ->filter()
            ->values()
            ->toArray();

        return array_map(function ($slug) use ($baseUrl, $today) {
            return [
                'loc' => $baseUrl . '/sahkosopimus/yritys/' . $slug,
                'lastmod' => $today,
                'changefreq' => 'weekly',
                'priority' => 0.7,
            ];
        }, $companies);
    }

    /**
     * Get city SEO page URLs based on municipalities in database.
     */
    public function getCityUrls(): array
    {
        $baseUrl = config('app.url');
        $today = Carbon::today()->toDateString();

        // Get unique municipalities from postcodes
        $cities = Postcode::select('municipal_name_fi_slug')
            ->whereNotNull('municipal_name_fi_slug')
            ->distinct()
            ->pluck('municipal_name_fi_slug')
            ->filter()
            ->values()
            ->toArray();

        return array_map(function ($citySlug) use ($baseUrl, $today) {
            return [
                'loc' => $baseUrl . '/sahkosopimus/' . $citySlug,
                'lastmod' => $today,
                'changefreq' => 'weekly',
                'priority' => 0.6,
            ];
        }, $cities);
    }

    /**
     * Get municipality page URLs (for the locations/paikkakunnat section).
     */
    public function getMunicipalityUrls(): array
    {
        $baseUrl = config('app.url');
        $today = Carbon::today()->toDateString();

        // Get unique municipalities from postcodes
        $municipalities = Postcode::select('municipal_name_fi_slug')
            ->whereNotNull('municipal_name_fi_slug')
            ->distinct()
            ->pluck('municipal_name_fi_slug')
            ->filter()
            ->values()
            ->toArray();

        return array_map(function ($slug) use ($baseUrl, $today) {
            return [
                'loc' => $baseUrl . '/sahkosopimus/paikkakunnat/' . $slug,
                'lastmod' => $today,
                'changefreq' => 'weekly',
                'priority' => 0.5,
            ];
        }, $municipalities);
    }

    /**
     * Generate XML sitemap content.
     */
    public function generateXml(): string
    {
        $urls = $this->getAllUrls();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1, 'UTF-8') . '</loc>' . "\n";
            $xml .= '    <lastmod>' . $url['lastmod'] . '</lastmod>' . "\n";
            $xml .= '    <changefreq>' . $url['changefreq'] . '</changefreq>' . "\n";
            $xml .= '    <priority>' . number_format($url['priority'], 1, '.', '') . '</priority>' . "\n";
            $xml .= '  </url>' . "\n";
        }

        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * Save sitemap to public directory.
     */
    public function saveToFile(string $path = null): string
    {
        $path = $path ?? public_path('sitemap.xml');
        $xml = $this->generateXml();

        file_put_contents($path, $xml);

        return $path;
    }

    /**
     * Get total URL count.
     */
    public function getUrlCount(): int
    {
        return count($this->getAllUrls());
    }
}
