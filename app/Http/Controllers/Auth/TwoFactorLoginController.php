<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auditoria;
use App\Services\TotpService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Segundo paso del login de metodo_auth=password (auth-prompt.md Fase 3).
 * Solo se llega acá después de que AuthenticatedSessionController::store()
 * validó la contraseña y dejó el estado "pendiente de 2FA" en sesión --
 * SIN haber llamado a Auth::login() todavía, así que ninguna ruta
 * protegida es alcanzable en este estado intermedio.
 */
class TwoFactorLoginController extends Controller
{
    public function __construct(private readonly TotpService $totp) {}

    public function show(Request $request): Response|RedirectResponse
    {
        if (! $this->usuarioPendiente($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/VerificarTotp');
    }

    public function store(Request $request): RedirectResponse
    {
        $usuario = $this->usuarioPendiente($request);

        if (! $usuario) {
            return redirect()->route('login')->withErrors([
                'email' => 'La verificación expiró -- iniciá sesión de nuevo.',
            ]);
        }

        $data = $request->validate(['codigo' => ['required', 'string']]);

        $throttleKey = Str::lower($usuario->email).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            event(new Lockout($request));
            $segundos = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'codigo' => trans('auth.throttle', ['seconds' => $segundos, 'minutes' => ceil($segundos / 60)]),
            ]);
        }

        $conTotp = $this->totp->verificarCodigo($usuario, $data['codigo']);
        $conRecuperacion = ! $conTotp && $this->totp->verificarYConsumirCodigoRecuperacion($usuario, $data['codigo']);

        if (! $conTotp && ! $conRecuperacion) {
            RateLimiter::hit($throttleKey);
            Auditoria::registrar('2fa.rechazado', $usuario);

            return back()->withErrors(['codigo' => 'Código inválido.']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->forget(['2fa.pendiente_user_id', '2fa.expira_en']);

        Auth::login($usuario);
        $request->session()->regenerate();
        Auditoria::registrar('login.exito', $usuario, detalle: ['con_recuperacion' => $conRecuperacion]);

        return redirect()->intended(route('landing', absolute: false));
    }

    private function usuarioPendiente(Request $request): ?User
    {
        $id = $request->session()->get('2fa.pendiente_user_id');
        $expiraEn = $request->session()->get('2fa.expira_en');

        if (! $id || ! $expiraEn || now()->timestamp > $expiraEn) {
            $request->session()->forget(['2fa.pendiente_user_id', '2fa.expira_en']);

            return null;
        }

        return User::find($id);
    }
}
