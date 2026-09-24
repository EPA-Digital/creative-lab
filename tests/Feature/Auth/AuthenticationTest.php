<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    /**
     * Contraseña correcta lleva al segundo paso (TOTP), NUNCA abre sesión
     * directo -- ver TotpLoginTest para el flujo completo de dos pasos.
     * Solo metodo_auth=password llega hasta acá; el default (google) se
     * rechaza (ver test de abajo).
     */
    public function test_una_contrasena_correcta_manda_al_segundo_paso_no_abre_sesion_directo(): void
    {
        $user = User::factory()->create([
            'metodo_auth' => User::METODO_PASSWORD,
            'password' => 'ContraseñaSegura2026',
            'activo' => true,
            'totp_confirmed_at' => now(),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'ContraseñaSegura2026',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('2fa.verificar'));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'metodo_auth' => User::METODO_PASSWORD,
            'password' => 'ContraseñaSegura2026',
            'activo' => true,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    /**
     * metodo_auth=google (default) no tiene contraseña utilizable -- ver
     * GoogleAuthTest para su propio camino de acceso.
     */
    public function test_un_usuario_metodo_auth_google_no_puede_autenticarse_con_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_un_usuario_desactivado_no_puede_autenticarse_aunque_la_contrasena_sea_correcta(): void
    {
        $user = User::factory()->create([
            'metodo_auth' => User::METODO_PASSWORD,
            'password' => 'ContraseñaSegura2026',
            'activo' => false,
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'ContraseñaSegura2026',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }
}
