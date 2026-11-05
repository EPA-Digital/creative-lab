<?php

namespace App\Http\Middleware;

use App\Models\Cuenta;
use App\Support\CuentaActual;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la cuenta actual de la petición (ver CuentaActual). Con {cuenta} en
 * la ruta (/{cuenta}/pais/{pais}/..., /cuentas/{cuenta}/editar), es esa; sin
 * ella queda TaDa como default perezoso (pantallas transversales, landing).
 * El acceso del usuario a la cuenta lo valida EnsureAccesoPais.
 */
class ResolverCuentaActual
{
    public function handle(Request $request, Closure $next): Response
    {
        $parametro = $request->route('cuenta');

        match (true) {
            $parametro instanceof Cuenta => CuentaActual::fijar($parametro),
            is_string($parametro) => CuentaActual::fijar(Cuenta::where('slug', $parametro)->firstOrFail()),
            default => CuentaActual::tadaPorDefecto(),
        };

        return $next($request);
    }
}
