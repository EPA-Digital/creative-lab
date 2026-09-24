<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            // Sin SMTP real (MAIL_MAILER=log, ver docs/pendientes-datos.md)
            // el link de "olvidé mi contraseña" no entrega nada -- un
            // 'cliente' que lo usara vería el mensaje de éxito genérico
            // de Laravel sin que llegue nada nunca, un callejón sin salida
            // silencioso. Se oculta a propósito (pedido explícito
            // 2026-09-24: "yo como admin la reseteas manualmente") hasta
            // que haya un proveedor de correo real -- la recuperación por
            // ahora es UsuariosController::resetearPassword(), gerente+.
            'canResetPassword' => false,
            'status' => session('status'),
        ]);
    }

    /**
     * Contraseña correcta != sesión abierta -- todo metodo_auth=password
     * tiene TOTP obligatorio (auth-prompt.md Fase 3). Acá solo se valida
     * la contraseña y se decide a dónde mandar al usuario; Auth::login()
     * recién pasa en TwoFactorLoginController tras el segundo paso.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $usuario = $request->authenticate();

        if (! $usuario->activo) {
            if ($usuario->estaPendienteDeAprobacion()) {
                Auditoria::registrar('login.password.rechazado', $usuario, detalle: ['razon' => 'pendiente_de_aprobacion']);

                return redirect()->route('login')->with(
                    'status',
                    'Tu cuenta está pendiente de aprobación -- vas a poder entrar apenas alguien de EPA confirme tu acceso.'
                );
            }

            Auditoria::registrar('login.password.rechazado', $usuario, detalle: ['razon' => 'desactivado']);

            return redirect()->route('login')->withErrors(['email' => 'Esta cuenta está desactivada.']);
        }

        // Sin TOTP confirmado (primera vez, o después de un reset por
        // "TOTP perdido" -- ver UsuariosController::resetearTotp()) hay
        // que re-enrolar antes de poder pasar por el segundo paso.
        if (! $usuario->tieneTotpConfirmado()) {
            $request->session()->put('2fa.enrolando_user_id', $usuario->id);

            return redirect()->route('2fa.enrolar');
        }

        Auditoria::registrar('login.password.paso1_exitoso', $usuario);

        $request->session()->put([
            '2fa.pendiente_user_id' => $usuario->id,
            '2fa.expira_en' => now()->addMinutes(5)->timestamp,
        ]);

        return redirect()->route('2fa.verificar');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auditoria::registrar('logout', $request->user());

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
