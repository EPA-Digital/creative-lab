<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Password::defaults()->uncompromised() (auth-prompt.md Fase 3)
        // pega contra la API real de Have I Been Pwned -- se fakea acá
        // para todos los tests, nunca depender de red externa ni de que
        // la contraseña de prueba "aparezca" o no en un breach real.
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }
}
