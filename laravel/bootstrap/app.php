<?php

use App\Services\Caching\ContractPriceCacheConflict;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust all proxies (Railway, load balancers, etc.)
        $middleware->trustProxies(at: '*');

        // Livewire updates for public cached pages don't need CSRF/session-backed initial GET requests.
        $middleware->validateCsrfTokens(except: [
            'livewire*/update',
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
        $exceptions->dontReport([ContractPriceCacheConflict::class]);
        $exceptions->render(function (ContractPriceCacheConflict $exception) {
            return response('Hintatiedot ovat tilapäisesti poissa käytöstä. Yritä hetken kuluttua uudelleen.', 503, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Retry-After' => '30',
                'Cache-Control' => 'no-store, private',
            ]);
        });
    })->create();
