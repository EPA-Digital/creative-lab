<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Único camino para que un usuario 'cliente' (sin cuenta @epa.digital)
 * obtenga acceso -- reemplaza el /register abierto de scaffold de Breeze.
 * El token lo genera UsuariosController::store() (admin-only); acá solo se
 * consume UNA vez (se limpia al aceptar, ver store()) para setear la
 * contraseña real.
 */
class InvitacionController extends Controller
{
    public function show(string $token): Response|RedirectResponse
    {
        $usuario = User::where('invitacion_token', $token)->first();
        if (! $usuario) {
            return redirect()->route('login')->withErrors(['email' => 'Este link de invitación ya no es válido.']);
        }

        return Inertia::render('Auth/AceptarInvitacion', [
            'token' => $token,
            'nombre' => $usuario->name,
            'email' => $usuario->email,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $usuario = User::where('invitacion_token', $token)->first();
        abort_unless($usuario, 404, 'Este link de invitación ya no es válido.');

        $data = $request->validate([
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $usuario->update([
            'password' => Hash::make($data['password']),
            'invitacion_token' => null,
            'email_verified_at' => now(),
        ]);

        // Invitado por un junior/senior -- pendiente de aprobación (ver
        // User::estaPendienteDeAprobacion()) hasta que gerente/director/
        // superadmin confirme los países. Ya puede setear su contraseña
        // (arriba), pero el login en sí queda para después de aprobar --
        // "ni siquiera puede loguearse hasta aprobar" (pedido explícito).
        if ($usuario->fresh()->estaPendienteDeAprobacion()) {
            return redirect()->route('login')->with(
                'status',
                'Tu cuenta quedó pendiente de aprobación -- vas a poder entrar apenas alguien de EPA confirme tu acceso.'
            );
        }

        Auth::login($usuario, remember: true);

        return redirect()->route('landing');
    }
}
