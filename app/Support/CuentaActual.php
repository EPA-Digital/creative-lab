<?php

namespace App\Support;

use App\Models\Cuenta;
use Illuminate\Support\Facades\Context;
use RuntimeException;

/**
 * La cuenta (TaDa, Openbank, Chedraui…) sobre la que corre la petición o el
 * job actual. Vive en el Context de Laravel, que viaja solo con cada job
 * encolado -- un job despachado desde una petición de Chedraui corre con
 * Chedraui sin pasarle nada.
 *
 * Regla del plan multi-cuenta: sin cuenta resuelta, toda consulta a datos
 * de negocio FALLA (ver PerteneceACuenta) en vez de traer los de todos.
 */
class CuentaActual
{
    private const CLAVE = 'cuenta_id';

    /** Marca "si nadie fija otra, es TaDa" -- se resuelve recién al usarse. */
    private const CLAVE_TADA_POR_DEFECTO = 'cuenta_tada_por_defecto';

    public static function id(): int
    {
        $id = Context::get(self::CLAVE);

        if (! $id && Context::get(self::CLAVE_TADA_POR_DEFECTO)) {
            $id = Cuenta::tada()->id;
            Context::add(self::CLAVE, $id);
        }

        if (! $id) {
            throw new RuntimeException('No hay cuenta actual: los datos de negocio solo se consultan dentro de una cuenta (ver CuentaActual).');
        }

        return (int) $id;
    }

    public static function hay(): bool
    {
        return (bool) Context::get(self::CLAVE) || (bool) Context::get(self::CLAVE_TADA_POR_DEFECTO);
    }

    /**
     * Transitorio (multi-cuenta, entrega 1): lo que no indica cuenta es de
     * TaDa, como todo lo anterior a las cuentas. No consulta la base hasta
     * que algo pide datos de negocio -- el login y demás páginas sin datos
     * no la tocan.
     */
    public static function tadaPorDefecto(): void
    {
        Context::add(self::CLAVE_TADA_POR_DEFECTO, true);
    }

    public static function fijar(Cuenta|int $cuenta): void
    {
        Context::add(self::CLAVE, $cuenta instanceof Cuenta ? $cuenta->id : $cuenta);
    }

    public static function olvidar(): void
    {
        Context::forget([self::CLAVE, self::CLAVE_TADA_POR_DEFECTO]);
    }

    /**
     * Corre $callback dentro de $cuenta y deja la cuenta anterior como
     * estaba -- para comandos y jobs que recorren varias cuentas.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function usar(Cuenta|int $cuenta, callable $callback): mixed
    {
        $anterior = Context::get(self::CLAVE);
        self::fijar($cuenta);

        try {
            return $callback();
        } finally {
            // Solo se restaura la cuenta explícita; la marca de TaDa por
            // defecto de la petición queda como estaba.
            $anterior ? Context::add(self::CLAVE, $anterior) : Context::forget(self::CLAVE);
        }
    }
}
