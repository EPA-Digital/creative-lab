<?php

namespace App\Support;

/**
 * Dónde se guardan los archivos que sube el equipo (logos de cuentas, fotos
 * de perfil): el bucket de GCS donde está configurado (producción) y el
 * disco local si no (desarrollo). Se sirven siempre desde el dashboard, así
 * no tocan la CSP ni necesitan URLs firmadas.
 */
class DiscoMedios
{
    public static function nombre(): string
    {
        return config('filesystems.disks.gcs.bucket') ? 'gcs' : 'local';
    }
}
