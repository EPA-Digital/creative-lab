<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Enrolamiento TOTP obligatorio para metodo_auth=password, parte del mismo
 * flujo de aceptar una invitación (ver InvitacionController::store()) --
 * la cuenta no se considera lista hasta confirmar el primer código. Igual
 * que TwoFactorLoginController, usa un marcador de sesión temporal en vez
 * de Auth::login() para no exponer ninguna ruta protegida antes de tiempo.
 */
class TotpEnrollmentController extends Controller
{
    public function __construct(private readonly TotpService $totp) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $usuario = $this->usuarioEnrolando($request);

        if (! $usuario) {
            return redirect()->route('login');
        }

        if ($usuario->totp_secret === null) {
            $usuario->update(['totp_secret' => $this->totp->generarSecreto()]);
            $usuario->refresh();
        }

        return Inertia::render('Auth/EnrolarTotp', [
            'qr' => $this->totp->qrSvgParaSecreto($usuario->email, $usuario->totp_secret),
            'secreto' => $usuario->totp_secret,
        ]);
    }

    public function store(Request $request): RedirectResponse|Response
    {
        $usuario = $this->usuarioEnrolando($request);
        abort_unless($usuario, 404);

        $data = $request->validate(['codigo' => ['required', 'string']]);

        if (! $this->totp->verificarCodigo($usuario, $data['codigo'])) {
            return back()->withErrors(['codigo' => 'Código inválido.']);
        }

        $codigosRecuperacion = $this->totp->generarCodigosRecuperacion();

        $usuario->update([
            'totp_confirmed_at' => now(),
            'totp_recovery_codes' => $this->totp->hashearCodigosRecuperacion($codigosRecuperacion),
        ]);

        $request->session()->forget('2fa.enrolando_user_id');

        // Invitado por un junior/senior -- pendiente de aprobación (ver
        // User::estaPendienteDeAprobacion()) igual que en el flujo de
        // Google, el TOTP ya queda enrolado para cuando lo aprueben -- de
        // cualquier forma, los códigos de recuperación se muestran ahora
        // mismo, es la única oportunidad.
        $pendiente = $usuario->fresh()->estaPendienteDeAprobacion();

        if (! $pendiente) {
            Auth::login($usuario);
        }

        // Los códigos de recuperación en texto plano solo existen en esta
        // respuesta -- nunca se persisten así, la vista los muestra una
        // única vez.
        return Inertia::render('Auth/CodigosRecuperacion', [
            'codigos' => $codigosRecuperacion,
            'continuarA' => $pendiente ? route('login') : route('landing'),
            'mensajePendiente' => $pendiente
                ? 'Tu cuenta quedó pendiente de aprobación -- vas a poder entrar apenas alguien de EPA confirme tu acceso.'
                : null,
        ]);
    }

    private function usuarioEnrolando(Request $request): ?User
    {
        $id = $request->session()->get('2fa.enrolando_user_id');

        return $id ? User::find($id) : null;
    }
}
