<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * auth-prompt.md Fase 4 -- cabeceras de seguridad en toda respuesta web.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_las_cabeceras_de_seguridad_estan_presentes(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Content-Security-Policy');
    }
}
