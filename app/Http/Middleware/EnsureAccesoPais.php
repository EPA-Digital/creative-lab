<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scope por país (2026-09-23, pedido explícito) -- aplica a TODOS los
 * roles por igual, incluido 'director': ni siquiera el rol con acceso
 * completo al dashboard ve un país que no tenga asignado en usuario_pais
 * (tabla que ya existía sin usar). Se asigna desde /usuarios (ver
 * UsuariosController), solo un 'director' puede tocar esas asignaciones
 * (Gate 'gestionar-usuarios').
 *
 * El slug de la URL (ej. "ecuador") no es el mismo valor que
 * usuario_pais.pais_id -- se resuelve a través de config('paises.{slug}
 * .codigo') -> Pais::codigo, igual que ya hacen los controllers
 * (AnalisisCreativoController, etc.).
 */
class EnsureAccesoPais
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('pais');
        $config = config("paises.{$slug}");
        abort_unless($config, 404, "País \"{$slug}\" no existe en config/paises.php.");

        $tieneAcceso = $request->user()->paises()->where('codigo', $config['codigo'])->exists();
        abort_unless($tieneAcceso, 403, 'No tenés acceso a este país -- pedile a un director que te lo asigne desde Usuarios.');

        return $next($request);
    }
}
