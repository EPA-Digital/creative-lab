<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * auth-prompt.md Fase 4 -- cabeceras de seguridad estándar para un
 * servicio público con datos de cliente. La CSP va en modo Report-Only:
 * app.blade.php tiene un <script> inline (tema antes del primer paint) y
 * Ziggy (@routes) inyecta otro -- una CSP script-src estricta los rompe
 * sin un nonce, que no se puede validar en este entorno sin desplegar
 * contra el dominio real. Report-Only deja ver en la consola qué
 * bloquearía sin romper nada todavía.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $csp = implode('; ', [
            "default-src 'self'",
            "frame-ancestors 'none'",
            "img-src 'self' data: https://storage.googleapis.com",
            "font-src 'self' https://fonts.bunny.net",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "script-src 'self' 'unsafe-inline'",
            "connect-src 'self'",
        ]);
        $response->headers->set('Content-Security-Policy-Report-Only', $csp);

        return $response;
    }
}
