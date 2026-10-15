<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyInternalSignature;
use App\Support\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Endpoint internal Node -> Laravel (kontrak §D.9).
            // TANPA middleware "web" (tidak ada session/CSRF); dijaga tanda tangan HMAC.
            Route::middleware(VerifyInternalSignature::class)
                ->prefix('internal/v1')
                ->group(base_path('routes/internal.php'));
        },
    )
   ->withMiddleware(function (Middleware $middleware): void {
        // Header keamanan + CSP untuk SEMUA respons, termasuk 401/404/419/429 (kontrak §I no. 8)
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('internal/*') || $request->expectsJson(),
        );

        // Semua error JSON mengikuti format kontrak §F
        $exceptions->render(fn (Throwable $e, Request $request) => ApiExceptionRenderer::render($e, $request));
    })->create();
