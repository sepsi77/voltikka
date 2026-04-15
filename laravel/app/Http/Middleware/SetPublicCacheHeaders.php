<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPublicCacheHeaders
{
    public const BROWSER_MAX_AGE = 300;
    public const SHARED_MAX_AGE = 3600;
    public const STALE_WHILE_REVALIDATE = 86400;

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldApply($request, $response)) {
            return $response;
        }

        $this->applyCacheHeaders($response);

        return $response;
    }

    public function applyCacheHeaders(Response $response): void
    {
        $response->setPublic();
        $response->setMaxAge(self::BROWSER_MAX_AGE);
        $response->setSharedMaxAge(self::SHARED_MAX_AGE);
        $response->setStaleWhileRevalidate(self::STALE_WHILE_REVALIDATE);
        $response->headers->remove('Pragma');
        $response->headers->remove('Expires');
        $response->headers->set('Vary', 'Accept-Encoding', false);
    }

    private function shouldApply(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || ! $response->isSuccessful()) {
            return false;
        }

        if (! str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')) {
            return false;
        }

        return $request->routeIs(
            'sahkosopimus.index',
            'seo.*',
            'cheapest.contracts',
            'companies.list',
            'locations'
        );
    }
}
