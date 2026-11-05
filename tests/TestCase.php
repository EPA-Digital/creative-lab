<?php

namespace Tests;

use App\Models\Pais;
use App\Support\CuentaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
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

        // Multi-cuenta: los modelos de negocio exigen una cuenta actual (ver
        // CuentaActual). Los tests que arman datos a mano lo hacen dentro de
        // TaDa, igual que todo lo que existía antes de las cuentas; los que
        // prueban otra cuenta la fijan ellos mismos.
        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            CuentaActual::tadaPorDefecto();

            // TaDa opera en todos los países (cuenta_pais) -- en producción
            // la migración los asignó; acá los países nacen en cada test.
            Pais::created(function (Pais $pais) {
                $tada = DB::table('cuentas')->where('slug', 'tada')->value('id');
                $tada && DB::table('cuenta_pais')->insertOrIgnore(['cuenta_id' => $tada, 'pais_id' => $pais->id]);
            });
        }
    }
}
