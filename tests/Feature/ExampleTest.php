<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * '/' quedó detrás de login (2026-09-23, ver routes/web.php) -- un
     * visitante sin sesión se redirige a /login, nunca ve la app.
     */
    public function test_un_visitante_sin_sesion_es_redirigido_a_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
