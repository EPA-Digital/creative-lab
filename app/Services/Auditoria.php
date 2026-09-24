<?php

namespace App\Services;

use App\Models\AuditoriaAcceso;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * auth-prompt.md Fase 5 -- bitácora append-only + log estructurado a
 * stderr (LOG_CHANNEL=stderr en producción). Nunca recibe contraseñas,
 * tokens ni códigos en $detalle -- cada call-site es responsable de no
 * pasarlos.
 */
class Auditoria
{
    public static function registrar(string $evento, ?User $usuario = null, ?string $emailIntentado = null, array $detalle = []): void
    {
        $ip = request()?->ip();
        $userAgent = request()?->userAgent();

        AuditoriaAcceso::create([
            'usuario_id' => $usuario?->id,
            'email_intentado' => $emailIntentado ?? $usuario?->email,
            'evento' => $evento,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'detalle' => $detalle,
            'creado_en' => now(),
        ]);

        Log::info('auditoria.'.$evento, [
            'usuario_id' => $usuario?->id,
            'email' => $emailIntentado ?? $usuario?->email,
            'ip' => $ip,
            'detalle' => $detalle,
        ]);
    }
}
