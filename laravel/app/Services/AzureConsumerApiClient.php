<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AzureConsumerApiClient
{
    private const BASE_URL = 'https://ev-shv-prod-app-wa-consumerapi1.azurewebsites.net';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 1000;

    /**
     * Fetch contracts for a specific postcode.
     *
     * @param string $postcode
     * @return array
     * @throws RequestException
     */
    public function fetchContractsForPostcode(string $postcode): array
    {
        $url = self::BASE_URL . "/api/productlist/{$postcode}";

        $response = Http::retry(self::MAX_RETRIES, self::RETRY_DELAY_MS, function ($exception, $request) {
            // Only retry on server errors or connection issues
            return $exception instanceof RequestException
                && ($exception->response?->serverError() || $exception->response === null);
        })->get($url);

        if ($response->failed()) {
            Log::error("Failed to fetch contracts for postcode {$postcode}", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RequestException($response);
        }

        return $response->json() ?? [];
    }

    /**
     * Fetch contracts for multiple postcodes.
     *
     * @param array $postcodes
     * @return array Array of all contracts (deduplicated by ID)
     * @throws RequestException
     */
    public function fetchContractsForPostcodes(array $postcodes): array
    {
        $allContracts = [];
        $processedIds = [];

        foreach ($postcodes as $postcode) {
            $contracts = $this->fetchContractsForPostcode($postcode);

            foreach ($contracts as $contract) {
                $id = $contract['Id'] ?? null;
                if ($id && !isset($processedIds[$id])) {
                    $allContracts[] = $contract;
                    $processedIds[$id] = true;
                }
            }
        }

        return $allContracts;
    }
}
