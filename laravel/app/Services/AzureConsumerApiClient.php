<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AzureConsumerApiClient
{
    private const BASE_URL = 'https://ev-shv-prod-app-wa-consumerapi1.azurewebsites.net';

    private const MAX_RETRIES = 3;

    private const RETRY_DELAY_MS = 1000;

    public function productListUrl(string $postcode): string
    {
        return self::BASE_URL."/api/productlist/{$postcode}";
    }

    /**
     * Fetch the raw product list response for one postcode. The staged import saves this
     * response as it is and parses it only in its store step (tasks/staged-api-imports/).
     *
     * @throws RequestException|ConnectionException after the retries
     */
    public function fetchProductList(string $postcode): Response
    {
        $response = Http::retry(self::MAX_RETRIES, self::RETRY_DELAY_MS, function ($exception, $request) {
            // Only retry on server errors or connection issues
            return $exception instanceof ConnectionException
                || ($exception instanceof RequestException
                    && ($exception->response?->serverError() || $exception->response === null));
        })->get($this->productListUrl($postcode));

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return $response;
    }
}
