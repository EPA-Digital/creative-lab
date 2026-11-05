<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Middleware\EnsureAccesoPais;
use Illuminate\Http\Request;

/**
 * Páginas fuera de /pais/{pais} (Usuarios, Cuentas) igual muestran el rail,
 * que necesita un país: el último que visitó el usuario si sigue teniendo
 * acceso, si no el primero habilitado al que tenga acceso.
 */
trait ResuelvePaisDeContexto
{
    private function paisDeContexto(Request $request): string
    {
        $codigosConAcceso = $request->user()->paises()->pluck('codigo')->all();
        $habilitados = collect(config('paises'))->filter(fn (array $c) => $c['habilitado'] ?? false);
        $conAcceso = $habilitados->filter(fn (array $c) => in_array($c['codigo'], $codigosConAcceso, true));

        $ultimo = $request->session()->get(EnsureAccesoPais::SESION_PAIS_ACTUAL);
        if ($ultimo && $conAcceso->has($ultimo)) {
            return $ultimo;
        }

        return $conAcceso->keys()->first() ?? $habilitados->keys()->first() ?? 'ecuador';
    }
}
