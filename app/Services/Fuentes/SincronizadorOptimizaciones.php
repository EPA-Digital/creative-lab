<?php

namespace App\Services\Fuentes;

use App\Models\CuentaPublicitaria;
use App\Models\Pais;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\TiktokApiClient;
use App\Support\CuentaActual;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Optimizaciones automáticas (2026-11-18, pedido explícito): port del
 * script Optis_Optimizaciones.gs del Daily Cuentas.
 *
 * - Meta: Activity Log de cada cuenta de Meta del país (/act_X/activities)
 *   -- presupuestos, pausas, activaciones, creación, estrategia de puja,
 *   con quién lo hizo (actor_name). Meta solo guarda ~1 semana hacia atrás,
 *   así que corre a diario (lo anterior entra importando la hoja).
 * - TikTok: no tiene historial en su API, así que se compara el estado
 *   actual (status y presupuesto de campañas, ad groups y anuncios) contra
 *   la última foto (plataforma_snapshot). La primera corrida solo arma la
 *   foto. Fecha = modify_time / create_time del objeto; sin responsable.
 *
 * No duplica: cada evento lleva un id externo único, y además se salta lo
 * que ya entró por la hoja (misma fecha, tipo y campaña).
 */
class SincronizadorOptimizaciones
{
    private const ZONAS = ['ecuador' => 'America/Guayaquil', 'panama' => 'America/Panama', 'peru' => 'America/Lima', 'mexico' => 'America/Mexico_City'];

    /** event_type de Meta → tipo (config/optimizaciones.php). Los del script y los nombres reales del Activity Log. */
    public const TIPO_META = [
        'update_ad_set_budget' => 'ajuste_dinero',
        'update_campaign_budget' => 'ajuste_dinero',
        'ad_set_budget_updated' => 'ajuste_dinero',
        'update_ad_set_daily_budget' => 'ajuste_dinero',
        'update_campaign_daily_budget' => 'ajuste_dinero',
        'update_ad_set_lifetime_budget' => 'ajuste_dinero',
        'update_campaign_group_budget' => 'ajuste_dinero',
        'update_campaign_group_high_demand_periods' => 'ajuste_dinero',
        'update_ad_bid_amount' => 'ajuste_dinero',
        'update_ad_set_bid_amount' => 'ajuste_dinero',
        'update_ad_set_bid_strategy' => 'ajuste_estrategia',
        'update_campaign_group_bid_strategy' => 'ajuste_estrategia',
        'ad_paused' => 'apagado_anuncio',
        'campaign_paused' => 'apagado_campania',
        'ad_set_paused' => 'apagado_grupo',
        'ad_active' => 'ajuste_optimizacion',
        'campaign_active' => 'ajuste_optimizacion',
        'ad_set_active' => 'ajuste_optimizacion',
        'create_campaign' => 'creacion_campania',
        'create_campaign_group' => 'creacion_campania',
        'create_ad_set' => 'creacion_grupo',
        'create_ad' => 'pauta_anuncios',
        'update_ad' => 'ajuste_anuncios',
        'update_ad_creative' => 'ajuste_anuncios',
        'update_ad_set_optimization_goal' => 'ajuste_anuncios',
        // Encendido / apagado como los manda el Activity Log real.
        'update_ad_run_status' => 'run_status:anuncio',
        'update_ad_set_run_status' => 'run_status:grupo',
        'update_campaign_run_status' => 'run_status:campania',
        'update_campaign_group_run_status' => 'run_status:campania',
    ];

    public function __construct(
        private readonly ?MetaApiClient $meta = null,
        private readonly ?TiktokApiClient $tiktok = null,
    ) {}

    /** @return array{meta: int, tiktok: int, errores: list<string>} */
    public function sincronizar(string $pais): array
    {
        $config = config("paises.{$pais}");
        $paisId = Pais::where('codigo', $config['codigo'])->value('id');
        $zona = $config['zona_horaria'] ?? self::ZONAS[$pais] ?? 'America/Mexico_City';
        $r = ['meta' => 0, 'tiktok' => 0, 'errores' => []];

        foreach (CuentaPublicitaria::where('pais_id', $paisId)->where('activa', true)->get() as $cp) {
            try {
                if ($cp->plataforma === 'meta' && $this->meta) {
                    $r['meta'] += $this->guardar($paisId, $this->cambiosMeta($cp, $zona));
                } elseif ($cp->plataforma === 'tiktok' && $this->tiktok) {
                    $r['tiktok'] += $this->sincronizarTiktok($paisId, $cp, $zona);
                }
            } catch (Throwable $e) {
                // Una cuenta que falla (token vencido, rate limit) no frena a las demás.
                $r['errores'][] = "{$cp->nombre}: {$e->getMessage()}";
            }
        }

        return $r;
    }

    // --- Meta ---------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function cambiosMeta(CuentaPublicitaria $cp, string $zona): array
    {
        $ultimo = DB::table('optimizaciones')->where('cuenta_id', CuentaActual::id())->where('pais_id', $cp->pais_id)
            ->where('origen', 'meta')->max('fecha');
        $desde = $ultimo ? CarbonImmutable::parse($ultimo)->subDays(2) : CarbonImmutable::now()->subDays(7);

        $filas = [];
        $after = null;
        for ($pagina = 0; $pagina < 25; $pagina++) {
            $params = [
                'fields' => 'event_time,event_type,actor_name,object_id,object_name,object_type,extra_data',
                'since' => $desde->startOfDay()->timestamp,
                'until' => now()->timestamp,
                'limit' => '500',
            ];
            if ($after) {
                $params['after'] = $after;
            }
            $data = $this->meta->get("act_{$cp->id_externo}/activities", $params);
            foreach ($data['data'] ?? [] as $item) {
                if ($fila = $this->filaMeta($item, $zona)) {
                    $filas[] = $fila;
                }
            }
            $after = ! empty($data['paging']['next']) ? ($data['paging']['cursors']['after'] ?? null) : null;
            if (! $after) {
                break;
            }
        }

        return $filas;
    }

    /** Una actividad de Meta → optimización (null si no es de las que se registran). */
    public function filaMeta(array $item, string $zona): ?array
    {
        $evento = $item['event_type'] ?? '';
        $tipo = self::TIPO_META[$evento] ?? null;
        if (! $tipo || empty($item['event_time'])) {
            return null;
        }
        $extra = is_array($item['extra_data'] ?? null) ? $item['extra_data'] : (json_decode((string) ($item['extra_data'] ?? ''), true) ?: []);
        $objeto = $item['object_name'] ?? '';

        $desc = $this->descripcionMeta($evento, $extra, $objeto, $zona);
        if (str_starts_with($tipo, 'run_status:')) {
            // Solo encendidos y apagados reales. Los estados de proceso (Procesamiento
            // pendiente, Revisión pendiente, Eliminado...) no son optimizaciones.
            $antes = self::estado($extra['old_value'] ?? $extra['old_status'] ?? null);
            $despues = self::estado($extra['new_value'] ?? $extra['new_status'] ?? null);
            // Pausar o encender pasa por "Procesamiento pendiente" (Activo → Procesamiento
            // pendiente → Inactivo); se registra el paso final, si lo hizo una persona
            // (actor "Meta" = resultado de revisión, no una decisión del equipo).
            if (! $despues || $antes === $despues || mb_strtolower(trim((string) ($item['actor_name'] ?? ''))) === 'meta') {
                return null;
            }
            $quien = substr($tipo, 11);
            $apagado = $despues === 'apagado';
            $tipo = $apagado ? "apagado_{$quien}" : 'ajuste_optimizacion';
            $entidad = ['anuncio' => 'Anuncio', 'grupo' => 'Grupo de anuncios', 'campania' => 'Campaña'][$quien];
            $desc = $entidad.($apagado ? ' pausado' : ' activado');
        }
        $fecha = Carbon::parse(is_numeric($item['event_time']) ? '@'.$item['event_time'] : $item['event_time'])->setTimezone($zona)->toDateString();

        return [
            'fecha' => $fecha,
            'medio' => 'meta',
            'campanias' => $objeto !== '' ? [$objeto] : [],
            'tipo' => $tipo,
            'descripcion' => $desc,
            'responsable' => Optimizaciones::responsable($item['actor_name'] ?? null) ?? 'Auto-Meta',
            'origen' => 'meta',
            'externo' => 'meta:'.substr(sha1($evento.'|'.($item['object_id'] ?? $objeto).'|'.$item['event_time'].'|'.json_encode($extra)), 0, 32),
        ];
    }

    private function descripcionMeta(string $evento, array $extra, string $objeto, string $zona = 'UTC'): string
    {
        if ($evento === 'update_campaign_group_high_demand_periods') {
            $mult = fn ($s) => isset($s['budget_value']) ? $s['budget_value'].(($s['budget_value_type'] ?? '') === 'MULTIPLIER' ? '%' : '') : null;
            $hora = fn ($t) => $t ? Carbon::createFromTimestamp((int) $t)->setTimezone($zona)->format('d/m H:i') : null;
            $ventana = fn ($s) => $s && $hora($s['start_time'] ?? null) && $hora($s['end_time'] ?? null) ? ' ('.$hora($s['start_time']).' a '.$hora($s['end_time']).')' : '';
            $nueva = $extra['new_budget_schedule'] ?? null;
            $vieja = $extra['old_budget_schedule'] ?? null;
            if (($extra['operation'] ?? '') === 'DELETE' || (! $nueva && $vieja)) {
                return 'Se eliminó el multiplicador de presupuesto de '.($mult($vieja) ?? '?').$ventana($vieja);
            }
            if ($nueva && $vieja) {
                // Meta manda solo lo que cambió: si no trae el nuevo valor, fue la ventana.
                if ($mult($nueva) === null || $mult($nueva) === $mult($vieja)) {
                    $fin = $hora($nueva['end_time'] ?? null);

                    return 'Se ajustó el multiplicador de presupuesto de '.($mult($vieja) ?? '?').($fin ? " para terminar el {$fin}" : $ventana($nueva));
                }

                return 'Se actualizó el multiplicador de presupuesto de '.$mult($vieja).' a '.$mult($nueva).$ventana($nueva);
            }

            return $nueva ? 'Se programó un multiplicador de presupuesto de '.($mult($nueva) ?? '?').$ventana($nueva) : 'Ajuste de horario de alta demanda';
        }
        if (str_contains($evento, 'budget') || $evento === 'update_ad_bid_amount' || $evento === 'update_ad_set_bid_amount') {
            $viejo = is_array($extra['old_value'] ?? null) ? ($extra['old_value']['old_value'] ?? null) : ($extra['old_value'] ?? null);
            $nuevo = is_array($extra['new_value'] ?? null) ? ($extra['new_value']['new_value'] ?? null) : ($extra['new_value'] ?? null);
            if (is_numeric($viejo) && is_numeric($nuevo)) {
                return self::textoPresupuesto($viejo / 100, $nuevo / 100);
            }

            return 'Ajuste de presupuesto';
        }

        return match ($evento) {
            'update_ad_set_bid_strategy', 'update_campaign_group_bid_strategy' => 'Estrategia: '.($extra['old_value'] ?? '').' a '.($extra['new_value'] ?? ''),
            'update_ad_set_optimization_goal' => 'Obj. optimización: '.($extra['old_value'] ?? '').' a '.($extra['new_value'] ?? ''),
            'campaign_paused' => 'Campaña pausada',
            'campaign_active' => 'Campaña activada',
            'ad_set_paused' => 'Grupo de anuncios pausado',
            'ad_set_active' => 'Grupo de anuncios activado',
            'ad_paused' => 'Anuncio pausado',
            'ad_active' => 'Anuncio activado',
            'create_campaign', 'create_campaign_group' => "Se creó campaña: {$objeto}",
            'create_ad_set' => "Se creó grupo de anuncios: {$objeto}",
            'create_ad' => "Se pautó anuncio: {$objeto}",
            'update_ad', 'update_ad_creative' => "Se actualizó anuncio: {$objeto}",
            default => str_replace('_', ' ', $evento),
        };
    }

    /** 'activo' | 'apagado' | null (estados intermedios o eliminado). */
    private static function estado(mixed $v): ?string
    {
        $t = Str::of((string) $v)->ascii()->lower()->toString();
        if ($t === '' || str_contains($t, 'pendiente') || str_contains($t, 'pending') || str_contains($t, 'elimin') || str_contains($t, 'delet') || str_contains($t, 'archiv')) {
            return null;
        }
        if (str_contains($t, 'inactiv') || str_contains($t, 'paus') || str_contains($t, 'desactiv')) {
            return 'apagado';
        }

        return str_contains($t, 'activ') ? 'activo' : null;
    }

    /** "Se ajusta ppto de $70 a $80 (+14%)" -- igual que el script. */
    public static function textoPresupuesto(float $viejo, float $nuevo): string
    {
        $t = 'Se ajusta ppto de $'.number_format($viejo, 0).' a $'.number_format($nuevo, 0);
        if ($viejo > 0) {
            $pct = ($nuevo - $viejo) / $viejo * 100;
            $t .= ' ('.($pct > 0 ? '+' : '').number_format($pct, 0).'%)';
        }

        return $t;
    }

    // --- TikTok --------------------------------------------------------------------

    /** Compara contra la foto, guarda lo nuevo y actualiza la foto. Devuelve cuántas se escribieron. */
    public function sincronizarTiktok(int $paisId, CuentaPublicitaria $cp, string $zona): int
    {
        $actual = [];
        foreach ([
            'campaign' => ['/campaign/get/', ['campaign_id', 'campaign_name', 'operation_status', 'budget', 'create_time', 'modify_time'], 'campaign_id', 'campaign_name'],
            'adgroup' => ['/adgroup/get/', ['adgroup_id', 'adgroup_name', 'campaign_id', 'operation_status', 'budget', 'create_time', 'modify_time'], 'adgroup_id', 'adgroup_name'],
            'ad' => ['/ad/get/', ['ad_id', 'ad_name', 'adgroup_id', 'operation_status', 'create_time', 'modify_time'], 'ad_id', 'ad_name'],
        ] as $tipo => [$ruta, $campos, $id, $nombre]) {
            for ($pagina = 1; $pagina <= 50; $pagina++) {
                $data = $this->tiktok->get($ruta, ['advertiser_id' => $cp->id_externo, 'fields' => $campos, 'page' => $pagina, 'page_size' => 1000]);
                foreach ($data['list'] ?? [] as $o) {
                    $actual["{$tipo}:{$o[$id]}"] = [
                        'tipo' => $tipo, 'nombre' => $o[$nombre] ?? '', 'status' => $o['operation_status'] ?? null,
                        'budget' => $tipo === 'ad' ? null : (isset($o['budget']) ? (float) $o['budget'] : null),
                        'creado' => $o['create_time'] ?? null, 'modificado' => $o['modify_time'] ?? null,
                    ];
                }
                if ($pagina >= ($data['page_info']['total_page'] ?? 1)) {
                    break;
                }
            }
        }

        $previo = DB::table('plataforma_snapshot')->where('cuenta_publicitaria_id', $cp->id)->get()->keyBy('clave');
        $filas = [];
        // Primera corrida: solo la foto base (no reporta todo lo que ya existía).
        if ($previo->isNotEmpty()) {
            $hoy = CarbonImmutable::now($zona)->toDateString();
            $dia = fn (?string $t) => $t && preg_match('/^(\d{4}-\d{2}-\d{2})/', $t, $m) ? $m[1] : $hoy;
            $entidad = ['campaign' => 'campaña', 'adgroup' => 'grupo de anuncios', 'ad' => 'anuncio'];
            foreach ($actual as $clave => $a) {
                $p = $previo[$clave] ?? null;
                if (! $p) {
                    $filas[] = $this->filaTiktok($dia($a['creado']), $a['nombre'], ['campaign' => 'creacion_campania', 'adgroup' => 'creacion_grupo', 'ad' => 'pauta_anuncios'][$a['tipo']],
                        ($a['tipo'] === 'ad' ? 'Se pautó anuncio: ' : 'Se creó '.$entidad[$a['tipo']].': ').$a['nombre'], "{$clave}:nuevo");

                    continue;
                }
                $fecha = $dia($a['modificado']);
                if ($p->status !== $a['status']) {
                    $apagado = $a['status'] === 'DISABLE';
                    $tipoStatus = $apagado ? ['campaign' => 'apagado_campania', 'adgroup' => 'apagado_grupo', 'ad' => 'apagado_anuncio'][$a['tipo']] : 'ajuste_optimizacion';
                    $filas[] = $this->filaTiktok($fecha, $a['nombre'], $tipoStatus, ucfirst($entidad[$a['tipo']]).($apagado ? ' pausada/o' : ' activada/o'), "{$clave}:status:{$a['status']}:{$a['modificado']}");
                }
                if ($a['tipo'] !== 'ad' && (float) $p->budget !== (float) $a['budget']) {
                    $filas[] = $this->filaTiktok($fecha, $a['nombre'], 'ajuste_dinero', self::textoPresupuesto((float) $p->budget, (float) $a['budget']), "{$clave}:budget:{$a['budget']}:{$a['modificado']}");
                }
            }
        }

        $escritas = $this->guardar($paisId, $filas);
        // La foto se actualiza DESPUÉS de guardar (como el fix del script): si algo
        // falla antes, la siguiente corrida vuelve a detectar esos cambios.
        DB::transaction(function () use ($cp, $actual) {
            DB::table('plataforma_snapshot')->where('cuenta_publicitaria_id', $cp->id)->delete();
            foreach (array_chunk(array_keys($actual), 500) as $claves) {
                DB::table('plataforma_snapshot')->insert(array_map(fn ($k) => [
                    'cuenta_publicitaria_id' => $cp->id, 'clave' => $k, 'tipo' => $actual[$k]['tipo'],
                    'nombre' => mb_substr($actual[$k]['nombre'], 0, 400), 'status' => $actual[$k]['status'],
                    'budget' => $actual[$k]['budget'], 'actualizado_en' => now(),
                ], $claves));
            }
        });

        return $escritas;
    }

    private function filaTiktok(string $fecha, string $nombre, string $tipo, string $desc, string $externo): array
    {
        return [
            'fecha' => $fecha, 'medio' => 'tiktok', 'campanias' => [$nombre], 'tipo' => $tipo, 'descripcion' => $desc,
            'responsable' => null, 'origen' => 'tiktok', 'externo' => 'tiktok:'.substr(sha1($externo), 0, 32),
        ];
    }

    // --- Guardar sin duplicar ---------------------------------------------------------

    /** @param list<array<string, mixed>> $filas */
    private function guardar(int $paisId, array $filas): int
    {
        if ($filas === []) {
            return 0;
        }
        $cuentaId = CuentaActual::id();
        $fechas = array_column($filas, 'fecha');
        // Lo que ya existe en esas fechas: por id externo y, para lo que vino de
        // la hoja (el mismo script ya lo había escrito), por fecha + tipo + campaña.
        $existentes = DB::table('optimizaciones')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)
            ->whereBetween('fecha', [min($fechas), max($fechas)])->get(['fecha', 'tipo', 'campanias', 'externo']);
        $externos = $existentes->pluck('externo')->filter()->flip()->all();
        $suaves = [];
        foreach ($existentes->whereNull('externo') as $e) {
            // Solo lo que entró por la hoja o a mano: dos ajustes reales el mismo día
            // a la misma campaña sí se guardan los dos (traen ids distintos).
            foreach (json_decode((string) $e->campanias, true) ?: [''] as $c) {
                $suaves[substr((string) $e->fecha, 0, 10).'|'.$e->tipo.'|'.mb_strtolower(trim($c))] = true;
            }
        }

        $nuevas = 0;
        $ahora = now();
        foreach ($filas as $f) {
            $suave = $f['fecha'].'|'.$f['tipo'].'|'.mb_strtolower(trim($f['campanias'][0] ?? ''));
            if (isset($externos[$f['externo']]) || isset($suaves[$suave])) {
                continue;
            }
            DB::table('optimizaciones')->insert([
                ...$f, 'campanias' => json_encode($f['campanias']), 'cuenta_id' => $cuentaId, 'pais_id' => $paisId,
                'creado_en' => $ahora, 'actualizado_en' => $ahora,
            ]);
            $externos[$f['externo']] = true;
            $nuevas++;
        }

        return $nuevas;
    }
}
