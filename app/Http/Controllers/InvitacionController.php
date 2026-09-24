<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Único camino para que un usuario 'cliente' (sin cuenta @epa.digital)
 * obtenga acceso -- reemplaza el /register abierto de scaffold de Breeze.
 * El token lo genera UsuariosController::store() (admin-only); acá solo se
 * consume UNA vez (se limpia al aceptar, ver store()) para setear la
 * contraseña real.
 *
 * El token se guarda hasheado (sha256, no bcrypt -- necesita comparación
 * exacta, no verificación lenta) y expira a las 72 horas (auth-prompt.md
 * Fase 3) -- nunca se persiste en texto plano, solo vive en la URL que se
 * manda a mano.
 */
class InvitacionController extends Controller
{
    public function show(string $token): Response|RedirectResponse
    {
        $usuario = $this->buscarPorToken($token);

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
        $usuario = $this->buscarPorToken($token);
        abort_unless($usuario, 404, 'Este link de invitación ya no es válido.');

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $usuario->update([
            'password' => Hash::make($data['password']),
            'invitacion_token' => null,
            'invitacion_expira_en' => null,
            'email_verified_at' => now(),
        ]);
        Auditoria::registrar('invitacion.aceptada', $usuario);

        // metodo_auth=password exige TOTP obligatorio en el mismo flujo de
        // aceptación (ver TotpEnrollmentController) -- la cuenta no se
        // considera lista hasta confirmar el primer código. Este mismo
        // link también se reusa para "reseteo de contraseña" (ver
        // UsuariosController::resetearPassword(), pedido explícito
        // 2026-09-24 -- sin SMTP real, un admin genera el link a mano en
        // vez de un correo de "olvidé mi contraseña") -- ahí el usuario
        // YA tiene TOTP confirmado, así que no hace falta re-enrolar, va
        // directo a loguearse con el authenticator que ya tenía.
        if ($usuario->metodo_auth === User::METODO_PASSWORD && ! $usuario->tieneTotpConfirmado()) {
            $request->session()->put('2fa.enrolando_user_id', $usuario->id);

            return redirect()->route('2fa.enrolar');
        }

        // Reset de contraseña con TOTP ya confirmado -- poner una
        // contraseña nueva NUNCA alcanza sola para abrir sesión, sigue
        // pidiendo el segundo factor exactamente como un login normal
        // (ver AuthenticatedSessionController::store()) en vez de loguear
        // directo -- si no, quien intercepte el link (Slack/WhatsApp)
        // podría entrar sin el authenticator.
        if ($usuario->metodo_auth === User::METODO_PASSWORD) {
            return $this->irAVerificarTotpOQuedarPendiente($request, $usuario);
        }

        return $this->intentarLoginOQuedarPendiente($usuario);
    }

    private function irAVerificarTotpOQuedarPendiente(Request $request, User $usuario): RedirectResponse
    {
        $usuario = $usuario->fresh();

        if ($usuario->estaPendienteDeAprobacion()) {
            return redirect()->route('login')->with(
                'status',
                'Tu cuenta quedó pendiente de aprobación -- vas a poder entrar apenas alguien de EPA confirme tu acceso.'
            );
        }

        if (! $usuario->activo) {
            return redirect()->route('login')->withErrors(['email' => 'Esta cuenta está desactivada.']);
        }

        $request->session()->put([
            '2fa.pendiente_user_id' => $usuario->id,
            '2fa.expira_en' => now()->addMinutes(5)->timestamp,
        ]);

        return redirect()->route('2fa.verificar');
    }

    /**
     * Invitado por un junior/senior -- pendiente de aprobación (ver
     * User::estaPendienteDeAprobacion()) hasta que gerente/director/
     * superadmin confirme los países. Ya puede setear su contraseña
     * (arriba), pero el login en sí queda para después de aprobar --
     * "ni siquiera puede loguearse hasta aprobar" (pedido explícito).
     */
    private function intentarLoginOQuedarPendiente(User $usuario): RedirectResponse
    {
        if ($usuario->fresh()->estaPendienteDeAprobacion()) {
            return redirect()->route('login')->with(
                'status',
                'Tu cuenta quedó pendiente de aprobación -- vas a poder entrar apenas alguien de EPA confirme tu acceso.'
            );
        }

        Auth::login($usuario);

        return redirect()->route('landing');
    }

    private function buscarPorToken(string $token): ?User
    {
        $usuario = User::where('invitacion_token', hash('sha256', $token))->first();

        if (! $usuario) {
            return null;
        }

        if ($usuario->invitacion_expira_en !== null && $usuario->invitacion_expira_en->isPast()) {
            return null;
        }

        return $usuario;
    }
}
