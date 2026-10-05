<?php

namespace App\Services\Ingesta;

use InvalidArgumentException;
use Throwable;

/**
 * Antes de guardar una cuenta publicitaria (Ajustes, superadmin), confirma
 * contra la API real que el token del proyecto la puede leer -- un ID mal
 * copiado o una cuenta sin acceso se descubre al guardar, no en la
 * siguiente importación (que la saltaría sin avisar). Devuelve el nombre
 * real de la cuenta para usarlo si no se tecleó uno.
 */
class ValidadorCuentaPublicitaria
{
    /**
     * Quita el prefijo "act_" de Meta y espacios -- se guarda solo el número.
     */
    public static function normalizarId(string $cuentaId): string
    {
        return preg_replace('/^act_/i', '', trim($cuentaId));
    }

    /**
     * Meta devuelve el nombre real de la cuenta. TikTok no: el token del
     * proyecto NO tiene permiso para /advertiser/info/ (code 40001,
     * verificado 2026-10-05 contra las cuentas reales de EC/MX), así que se
     * valida con /ad/get/ -- el mismo endpoint que usa la importación, que
     * es justo el acceso que importa -- y el nombre queda null.
     *
     * @throws InvalidArgumentException si el token no puede leer la cuenta
     */
    public function nombreRemoto(string $plataforma, string $cuentaId): ?string
    {
        try {
            if ($plataforma === 'meta') {
                $data = MetaApiClient::fromConfig()->get("act_{$cuentaId}", ['fields' => 'name']);
                if (empty($data['name'])) {
                    throw new InvalidArgumentException('la API no devolvió la cuenta');
                }

                return $data['name'];
            }

            TiktokApiClient::fromConfig()->get('/ad/get/', ['advertiser_id' => $cuentaId, 'page_size' => 1]);

            return null;
        } catch (Throwable $e) {
            throw new InvalidArgumentException("No se pudo leer la cuenta {$cuentaId} en ".self::etiqueta($plataforma)." con el token del proyecto -- revisá el ID o que el token tenga acceso ({$e->getMessage()}).");
        }
    }

    private static function etiqueta(string $plataforma): string
    {
        return $plataforma === 'meta' ? 'Meta' : 'TikTok';
    }
}
