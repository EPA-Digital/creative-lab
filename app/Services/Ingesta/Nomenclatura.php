<?php

namespace App\Services\Ingesta;

use App\Models\Cuenta;
use App\Support\CuentaActual;
use Illuminate\Support\Facades\Context;

/**
 * Punto de entrada del pipeline para leer un nombre de campaña/anuncio en
 * la cuenta actual: con reglas propias (ReglasNomenclatura) si la cuenta
 * las tiene; si no, el clasificador de siempre de TaDa (ClasificadorNombres).
 *
 * Las reglas se leen una vez por petición/job (Context oculto), no una vez
 * por anuncio -- un import procesa miles.
 */
class Nomenclatura
{
    /**
     * Conjunto y objetivo solo los usan las reglas propias; TaDa los ignora.
     *
     * @return array{plataforma: ?string, funnel: ?string, tipoCuenta: ?string, patron: ?string, arte: ?string, videoFilename: ?string, formato: ?string}
     */
    public static function parsear(?string $campaignRaw, ?string $adRaw, ?string $conjuntoRaw = null, ?string $objetivo = null, ?string $postId = null): array
    {
        $reglas = self::reglasDeLaCuentaActual();

        return $reglas
            ? ReglasNomenclatura::parsear($reglas, $campaignRaw, $adRaw, $conjuntoRaw, $objetivo, $postId)
            : ClasificadorNombres::parsearNombre($campaignRaw, $adRaw);
    }

    /** @return array<string, mixed>|null */
    private static function reglasDeLaCuentaActual(): ?array
    {
        $clave = 'nomenclatura.cuenta_'.CuentaActual::id();
        if (! Context::hasHidden($clave)) {
            $cuenta = Cuenta::find(CuentaActual::id(), ['id', 'slug', 'nomenclatura']);
            // TaDa siempre con su clasificador (pedido explícito 2026-11-05):
            // ya funciona y sus reglas cuadran la venta real y el BRD de Ecuador.
            $reglas = $cuenta?->slug === Cuenta::SLUG_TADA ? null : $cuenta?->nomenclatura;
            Context::addHidden($clave, $reglas ? ReglasNomenclatura::normalizar($reglas) : false);
        }

        return Context::getHidden($clave) ?: null;
    }
}
