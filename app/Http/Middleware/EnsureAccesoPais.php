<?php

namespace App\Http\Middleware;

use App\Models\Cuenta;
use App\Services\Auditoria;
use App\Support\CuentaActual;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scope por cuenta y país -- aplica a TODOS los roles por igual, incluido
 * 'director': para ver /{cuenta}/pais/{pais}/... el usuario necesita la
 * cuenta (usuario_cuenta, multi-cuenta 2026-11-04) Y el país (usuario_pais,
 * 2026-09-23), y la cuenta tiene que operar en ese país (cuenta_pais). Ambas
 * cosas se asignan desde /usuarios.
 *
 * El slug de la URL (ej. "ecuador") no es el mismo valor que
 * usuario_pais.pais_id -- se resuelve a través de config('paises.{slug}
 * .codigo') -> Pais::codigo, igual que ya hacen los controllers
 * (AnalisisCreativoController, etc.).
 *
 * La cuenta ya quedó fijada por ResolverCuentaActual (que corre antes de
 * todo). Acá se saca {cuenta} de los parámetros de la ruta para que los
 * controllers sigan recibiendo ($pais, ...) como siempre, y se deja como
 * default de route() para que las URLs generadas la lleven solas.
 */
class EnsureAccesoPais
{
    public const SESION_PAIS_ACTUAL = 'pais_actual';

    public const SESION_CUENTA_ACTUAL = 'cuenta_actual';

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('pais');
        $config = config("paises.{$slug}");
        abort_unless($config, 404, "País \"{$slug}\" no existe en config/paises.php.");

        $cuenta = Cuenta::findOrFail(CuentaActual::id());
        $usuario = $request->user();

        abort_unless($usuario->cuentas()->whereKey($cuenta->id)->exists(), 403, "No tienes acceso a {$cuenta->nombre} -- pide a un director que te la asigne desde Usuarios.");
        abort_unless($cuenta->activa, 403, "{$cuenta->nombre} está desactivada.");
        $tieneAcceso = $usuario->paises()->where('codigo', $config['codigo'])->exists();
        abort_unless($tieneAcceso, 403, 'No tenés acceso a este país -- pedile a un director que te lo asigne desde Usuarios.');
        abort_unless($cuenta->paises()->where('codigo', $config['codigo'])->exists(), 404, "{$cuenta->nombre} no opera en {$config['nombre']}.");

        $request->route()->forgetParameter('cuenta');
        URL::defaults(['cuenta' => $cuenta->slug]);

        // Último país y cuenta visitados (pedido explícito 2026-10-06) -- las
        // pantallas transversales (Usuarios, Cuentas) los usan como contexto
        // del rail, para no "cambiar de país" al entrar a ellas.
        $request->session()->put(self::SESION_PAIS_ACTUAL, $slug);
        $request->session()->put(self::SESION_CUENTA_ACTUAL, $cuenta->slug);

        // Auditoría (Fase 5) -- solo la primera vez por sesión, no en cada
        // request dentro del mismo país (sería demasiado ruido).
        $marca = "auditoria.pais_visitado.{$cuenta->slug}.{$config['codigo']}";
        if (! $request->session()->get($marca)) {
            $request->session()->put($marca, true);
            Auditoria::registrar('pais.primera_visita', $usuario, detalle: ['cuenta' => $cuenta->slug, 'pais' => $config['codigo']]);
        }

        return $next($request);
    }
}
