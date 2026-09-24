<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * auth-prompt.md Fase 4 -- cabeceras de seguridad estándar para un
 * servicio público con datos de cliente.
 *
 * CSP enforced (2026-09-24, validado contra el servicio real en
 * producción) -- estuvo en Report-Only hasta confirmar que
 * script-src/style-src 'unsafe-inline' ya cubren los dos <script>
 * inline de app.blade.php (tema antes del primer paint, Ziggy @routes)
 * y que img-src coincide con el formato real que genera
 * StorageObject::signedUrl() del SDK de PHP (`storage.googleapis.com/
 * {bucket}/...`, estilo path -- no el subdominio `{bucket}.storage.
 * googleapis.com` que usa por default el CLI de gcloud).
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
        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
