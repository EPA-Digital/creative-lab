<?php

namespace App\Services\Presencia;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quién está usando el dashboard (2026-11-11), a partir de la tabla
 * `sessions` (SESSION_DRIVER=database): cada request -- y el aviso de
 * actividad que manda cada pestaña mientras alguien la usa -- actualiza
 * `last_activity` de su sesión; al cerrar sesión la fila se borra.
 *
 * - en_linea: actividad en los últimos MINUTOS_EN_LINEA.
 * - inactivo: la sesión sigue viva (dentro de SESSION_LIFETIME) pero sin uso reciente.
 * - desconectado: sin sesión viva (cerró sesión o se le venció).
 */
class Presencia
{
    public const MINUTOS_EN_LINEA = 5;

    public const EN_LINEA = 'en_linea';

    public const INACTIVO = 'inactivo';

    public const DESCONECTADO = 'desconectado';

    /**
     * @param  Collection<int, User>  $usuarios
     * @return array<int, array{estado: string, ultima_actividad: ?string}> por user id
     */
    public static function de(Collection $usuarios): array
    {
        $ahora = CarbonImmutable::now();
        $vigenciaDesde = $ahora->subMinutes((int) config('session.lifetime'))->getTimestamp();

        $sesiones = config('session.driver') === 'database'
            ? DB::table(config('session.table', 'sessions'))
                ->whereIn('user_id', $usuarios->pluck('id'))
                ->where('last_activity', '>=', $vigenciaDesde)
                ->groupBy('user_id')
                ->pluck(DB::raw('MAX(last_activity)'), 'user_id')
            : collect();

        $resultado = [];
        foreach ($usuarios as $u) {
            $enSesion = isset($sesiones[$u->id]) ? CarbonImmutable::createFromTimestamp((int) $sesiones[$u->id]) : null;
            $guardada = $u->ultima_actividad_en ? CarbonImmutable::parse($u->ultima_actividad_en) : null;
            // La más reciente de las dos: la sesión cuenta cualquier request;
            // la guardada sobrevive al cierre de sesión.
            $ultima = $enSesion && $guardada ? $enSesion->max($guardada) : ($enSesion ?? $guardada);

            $estado = match (true) {
                $enSesion === null => self::DESCONECTADO,
                $ultima->greaterThanOrEqualTo($ahora->subMinutes(self::MINUTOS_EN_LINEA)) => self::EN_LINEA,
                default => self::INACTIVO,
            };

            $resultado[$u->id] = ['estado' => $estado, 'ultima_actividad' => $ultima?->toIso8601String()];
        }

        return $resultado;
    }
}
