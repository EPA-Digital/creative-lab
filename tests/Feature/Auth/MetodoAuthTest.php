<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\SesionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * auth-prompt.md Fase 1: metodo_auth por usuario, regla dura para correos
 * del dominio epa.digital, normalización de correo e invalidación de
 * sesiones al desactivar.
 */
class MetodoAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_usuario_nuevo_sin_metodo_auth_explicito_queda_en_google_por_default(): void
    {
        $usuario = User::factory()->create(['email' => 'sinmetodo@afuera.com']);

        $this->assertSame(User::METODO_GOOGLE, $usuario->fresh()->metodo_auth);
    }

    public function test_un_correo_epa_digital_no_puede_tener_metodo_auth_password(): void
    {
        $this->expectException(InvalidArgumentException::class);

        User::factory()->create([
            'email' => 'empleado@epa.digital',
            'metodo_auth' => User::METODO_PASSWORD,
        ]);
    }

    public function test_un_correo_epa_digital_con_metodo_auth_google_explicito_si_se_puede_crear(): void
    {
        $usuario = User::factory()->create([
            'email' => 'empleado@epa.digital',
            'metodo_auth' => User::METODO_GOOGLE,
        ]);

        $this->assertSame(User::METODO_GOOGLE, $usuario->fresh()->metodo_auth);
    }

    public function test_el_correo_se_normaliza_a_minusculas_y_sin_espacios(): void
    {
        $usuario = User::factory()->create(['email' => '  Cliente@Afuera.COM  ']);

        $this->assertSame('cliente@afuera.com', $usuario->fresh()->email);
    }

    public function test_desactivar_un_usuario_borra_sus_sesiones_vivas(): void
    {
        $usuario = User::factory()->create(['rol' => 'cliente']);

        DB::table('sessions')->insert([
            'id' => 'sesion-de-prueba',
            'user_id' => $usuario->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('datos'),
            'last_activity' => now()->timestamp,
        ]);

        SesionService::invalidarSesionesDe($usuario);

        $this->assertDatabaseMissing('sessions', ['user_id' => $usuario->id]);
    }
}
