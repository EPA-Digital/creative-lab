<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auditoria;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierre de sesión por inactividad (2026-11-27, pedido explícito): EPA a los
 * 60 minutos sin uso, el resto (clientes) a los 15 (config/auth.php).
 *
 * "Uso" es cualquier petición menos las automáticas, que mandan el header
 * X-Sin-Actividad (recarga de En vivo, seguimiento de importaciones,
 * presencia de Usuarios). Mover el mouse o teclear sin navegar también
 * cuenta: lo avisa el latido de presencia (POST /perfil/actividad). Al
 * vencerse, cierra la sesión y manda al login con el aviso.
 */
class CerrarSesionPorInactividad
{
    public const MENSAJE = 'Se cerró tu sesión por inactividad.';

    public static function limiteMinutos(?User $user): int
    {
        return (int) ($user?->esEpa() ? config('auth.inactividad.epa', 60) : config('auth.inactividad.externos', 15));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $ultima = $request->session()->get('ultima_actividad');
        if ($ultima && now()->timestamp - (int) $ultima > self::limiteMinutos($user) * 60) {
            Auditoria::registrar('logout_inactividad', $user);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                // La página lo manda al login (resources/js/bootstrap.js) y ahí sale el aviso.
                $request->session()->flash('status', self::MENSAJE);

                return response()->json(['message' => self::MENSAJE, 'inactividad' => true], 401);
            }

            return redirect()->route('login')->with('status', self::MENSAJE);
        }

        if (! $ultima || ! $request->hasHeader('X-Sin-Actividad')) {
            $request->session()->put('ultima_actividad', now()->timestamp);
        }

        return $next($request);
    }
}
