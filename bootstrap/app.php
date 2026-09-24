<?php

use App\Http\Middleware\EnsureAccesoPais;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias(['acceso-pais' => EnsureAccesoPais::class]);

        // `at: '*'` -- en Cloud Run el único camino hacia el contenedor es
        // el proxy de borde de Google (GFE), que agrega X-Forwarded-Proto/
        // For/Host/Port; no hay una IP fija publicada para confiar por
        // dirección (a diferencia de un proxy propio con IP conocida), así
        // que "confiar en todos" es el patrón recomendado para este
        // ingress específico, no una concesión de seguridad. Sí se acota
        // explícitamente a los headers X-Forwarded-* estándar (en vez del
        // default "todos los headers, incluido Forwarded RFC 7239") --
        // auth-prompt.md Fase 4 pide validar esto contra el proxy real de
        // Cloud Run antes de ir a producción, no se pudo probar en este
        // entorno.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
