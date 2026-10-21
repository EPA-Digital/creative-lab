<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auditoria;
use App\Services\SesionService;
use App\Services\TotpService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "¿Olvidaste tu contraseña?" sin correo (pedido explícito 2026-10-21) --
 * MAIL_MAILER=log sigue en producción (ver docs/pendientes-datos.md), así
 * que la identidad no se prueba con un link por email sino con el segundo
 * factor que todo metodo_auth=password ya tiene obligatorio: un código
 * TOTP vigente o un código de recuperación de un solo uso.
 *
 * Sin TOTP confirmado (nunca enroló, o se lo resetearon) no hay con qué
 * probar identidad -- ese caso sigue siendo
 * UsuariosController::resetearPassword(), gerente+.
 */
class RecuperarPasswordController extends Controller
{
    public function __construct(private readonly TotpService $totp) {}

    public function create(): Response
    {
        return Inertia::render('Auth/RecuperarPassword');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'codigo' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $email = Str::lower(trim($data['email']));
        $throttleKey = 'recuperar-password|'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            event(new Lockout($request));
            $segundos = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'codigo' => trans('auth.throttle', ['seconds' => $segundos, 'minutes' => ceil($segundos / 60)]),
            ]);
        }

        $usuario = User::where('email', $email)
            ->where('metodo_auth', User::METODO_PASSWORD)
            ->where('activo', true)
            ->first();

        $valido = $usuario
            && $usuario->tieneTotpConfirmado()
            && ($this->totp->verificarCodigo($usuario, $data['codigo'])
                || $this->totp->verificarYConsumirCodigoRecuperacion($usuario, $data['codigo']));

        // Mismo mensaje para correo inexistente, cuenta sin TOTP y código
        // incorrecto -- no revelar qué correos tienen cuenta.
        if (! $valido) {
            RateLimiter::hit($throttleKey);
            Auditoria::registrar('password.recuperacion_rechazada', $usuario, emailIntentado: $email);

            return back()->withErrors(['codigo' => 'El correo o el código no son válidos.']);
        }

        RateLimiter::clear($throttleKey);

        $usuario->update(['password' => $data['password']]);
        SesionService::invalidarSesionesDe($usuario);
        Auditoria::registrar('password.recuperada', $usuario);

        // Sin Auth::login() acá: el login normal vuelve a pedir contraseña
        // + TOTP, nunca alcanza con haber recuperado la contraseña.
        return redirect()->route('login')->with('status', 'Listo, tu contraseña se actualizó. Ya podés entrar con la nueva.');
    }
}
