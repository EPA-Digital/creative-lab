<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Confía en el header X-Forwarded-Proto de cualquier proxy (2026-09-02,
        // demo por ngrok) -- sin esto, Laravel genera las URLs de asset()/
        // route() como http:// aunque la petición real haya llegado por
        // https:// (ngrok termina TLS y reenvía por HTTP puro internamente),
        // lo que el navegador bloquea como contenido mixto -- página en
        // blanco, sin ningún error visible de Laravel. Server local propio,
        // nunca expuesto directo a internet sin túnel -- confiar en todos
        // los proxies acá no abre una superficie nueva real.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
