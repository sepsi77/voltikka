<?php

namespace App\Services\Caching;

use Illuminate\Http\Request;

class PublicPriceCalculationPolicy
{
    private ?Request $permittedRequest = null;

    public function allowsCalculation(): bool
    {
        if (! app()->bound('request')) {
            return true;
        }

        $request = request();

        // Artisan has no HTTP method. CLI HTTP test requests still have one.
        if ($request->server('REQUEST_METHOD') === null && $request->route() === null) {
            return true;
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return false;
        }

        return $this->permittedRequest === $request;
    }

    public function allowUserAction(): void
    {
        $this->permittedRequest = request();
    }
}
