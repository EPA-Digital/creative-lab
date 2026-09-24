<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * auth-prompt.md Fase 6 -- ninguna respuesta de Inertia expone campos
 * sensibles del usuario (google_id, secreto TOTP, hashes de recuperación).
 */
class HandleInertiaRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_auth_user_compartido_no_incluye_campos_sensibles(): void
    {
        $usuario = User::factory()->create([
            'rol' => 'director',
            'google_id' => 'google-secreto-123',
        ]);

        $response = $this->actingAs($usuario)->get('/');

        $response->assertInertia(fn ($page) => $page
            ->where('auth.user.id', $usuario->id)
            ->where('auth.user.email', $usuario->email)
            ->missing('auth.user.google_id')
            ->missing('auth.user.password')
            ->missing('auth.user.totp_secret')
            ->missing('auth.user.totp_recovery_codes'));
    }
}
