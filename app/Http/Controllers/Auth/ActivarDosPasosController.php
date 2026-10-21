<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Activar el 2FA estando YA logueado (pedido explícito 2026-10-21) -- para
 * quien entró sin pasar por TotpEnrollmentController: clientes que entran
 * con Google, o invitados de antes de que el form de invitar mandara
 * 'metodo'. Lo dispara el aviso "Aún no tienes la verificación en dos
 * pasos" del layout. Una vez confirmado, el login (contraseña o Google,
 * ver GoogleAuthController) pide el código siempre.
 */
class ActivarDosPasosController extends Controller
{
    public function __construct(private readonly TotpService $totp) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $usuario = $request->user();

        if ($usuario->tieneTotpConfirmado()) {
            return redirect()->route('landing');
        }

        if ($usuario->totp_secret === null) {
            $usuario->update(['totp_secret' => $this->totp->generarSecreto()]);
            $usuario->refresh();
        }

        return Inertia::render('Auth/EnrolarTotp', [
            'qr' => $this->totp->qrSvgParaSecreto($usuario->email, $usuario->totp_secret),
            'secreto' => $usuario->totp_secret,
            'postUrl' => route('seguridad.2fa'),
            'obligatorio' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse|Response
    {
        $usuario = $request->user();

        if ($usuario->tieneTotpConfirmado()) {
            return redirect()->route('landing');
        }

        $data = $request->validate(['codigo' => ['required', 'string']]);

        if (! $this->totp->verificarCodigo($usuario, $data['codigo'])) {
            Auditoria::registrar('totp.enrolamiento_rechazado', $usuario);

            return back()->withErrors(['codigo' => 'Código inválido.']);
        }

        $codigosRecuperacion = $this->totp->generarCodigosRecuperacion();

        $usuario->update([
            'totp_confirmed_at' => now(),
            'totp_recovery_codes' => $this->totp->hashearCodigosRecuperacion($codigosRecuperacion),
        ]);
        Auditoria::registrar('totp.enrolado', $usuario, detalle: ['desde' => 'sesion_activa']);

        // Única vez que los códigos existen en texto plano -- ver
        // TotpEnrollmentController::store().
        return Inertia::render('Auth/CodigosRecuperacion', [
            'codigos' => $codigosRecuperacion,
            'continuarA' => route('landing'),
        ]);
    }
}
