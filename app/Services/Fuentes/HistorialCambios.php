<?php

namespace App\Services\Fuentes;

use App\Models\AuditoriaAcceso;
use App\Models\Cuenta;
use App\Models\User;
use App\Support\CuentaActual;
use Illuminate\Support\Carbon;

/**
 * Historial de cambios (2026-11-17, pedido explícito: "dónde veo el
 * historial... para ver los cambios generales"): los eventos de la
 * auditoría de un país y cuenta, en frases legibles -- Daily, Control de
 * gasto, cargas de datos y capturas manuales.
 */
class HistorialCambios
{
    /** área → prefijos de evento */
    public const AREAS = [
        'daily' => ['nombre' => 'Daily', 'eventos' => ['daily.']],
        'control' => ['nombre' => 'Control de gasto', 'eventos' => ['control.']],
        'datos' => ['nombre' => 'Datos', 'eventos' => ['fuentes.importadas', 'medios.', 'datos.mes_eliminado']],
        'fuentes' => ['nombre' => 'Fuentes', 'eventos' => ['fuentes.venta_real']],
    ];

    private const CAMPOS = [
        'budget' => 'el budget', 'status' => 'el status', 'funnel' => 'el funnel', 'nombre_comun' => 'el nombre común',
        'daily_plataforma' => 'el daily de plataforma', 'kpi_installs' => 'el KPI de installs', 'kpi_nc' => 'el KPI de NC',
        'kpi_recompras' => 'el KPI de recompras', 'kpi_orders' => 'el KPI de orders', 'region' => 'la región',
        'responsable' => 'el responsable', 'meta_installs' => 'la meta de installs', 'meta_nc' => 'la meta de NC',
        'meta_orders' => 'la meta de orders', 'notas' => 'las notas', 'fecha_inicio' => 'el inicio del periodo',
        'fecha_fin' => 'el fin del periodo',
    ];

    private const DIAS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * @param  array{area?: ?string, usuario?: ?int, antes?: ?int}  $filtros
     * @return array{eventos: list<array<string, mixed>>, hayMas: bool, usuarios: list<array{id: int, nombre: string}>}
     */
    public function listar(string $pais, string $codigo, array $filtros = [], int $limite = 150): array
    {
        $prefijos = $filtros['area'] ?? null
            ? (self::AREAS[$filtros['area']]['eventos'] ?? [])
            : array_merge(...array_column(self::AREAS, 'eventos'));

        $base = AuditoriaAcceso::query()
            ->where(function ($q) use ($prefijos) {
                foreach ($prefijos as $p) {
                    str_ends_with($p, '.') ? $q->orWhere('evento', 'like', $p.'%') : $q->orWhere('evento', $p);
                }
            })
            ->where(fn ($q) => $q->where('detalle->pais', $pais)->orWhere('detalle->pais', $codigo))
            // Lo de antes de guardar la cuenta (2026-11-17) era todo de TaDa.
            ->where(function ($q) {
                $q->where('detalle->cuenta_id', CuentaActual::id());
                if (CuentaActual::id() === Cuenta::tada()->id) {
                    $q->orWhereNull('detalle->cuenta_id');
                }
            });

        $usuarios = (clone $base)->whereNotNull('usuario_id')->distinct()->pluck('usuario_id');

        $filas = (clone $base)
            ->when($filtros['usuario'] ?? null, fn ($q, $u) => $q->where('usuario_id', $u))
            ->when($filtros['antes'] ?? null, fn ($q, $id) => $q->where('id', '<', $id))
            ->with('usuario:id,name')
            ->orderByDesc('id')
            ->limit($limite + 1)
            ->get();

        return [
            'eventos' => $filas->take($limite)->map(fn ($e) => [
                'id' => $e->id,
                'en' => $e->creado_en?->toIso8601String(),
                'quien' => $e->usuario?->name ?? $e->email_intentado ?? 'Alguien',
                'area' => $this->area($e->evento),
                'texto' => $this->describir($e->evento, $e->detalle ?? []),
                'detalle' => collect($e->detalle ?? [])->except(['pais', 'cuenta_id'])->all(),
            ])->values()->all(),
            'hayMas' => $filas->count() > $limite,
            'usuarios' => User::whereIn('id', $usuarios)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name])->all(),
        ];
    }

    private function area(string $evento): string
    {
        foreach (self::AREAS as $id => $a) {
            foreach ($a['eventos'] as $p) {
                if (str_ends_with($p, '.') ? str_starts_with($evento, $p) : $evento === $p) {
                    return $id;
                }
            }
        }

        return 'otro';
    }

    /** Frase legible del evento. */
    public function describir(string $evento, array $d): string
    {
        $camp = fn () => $d['campania'] ?? $d['clave'] ?? 'una campaña';
        $mes = fn () => isset($d['mes']) ? self::mes($d['mes']) : '';
        $tipo = fn () => isset($d['tipo']) && $d['tipo'] !== 'paid' ? ' '.strtoupper($d['tipo']) : '';

        return match ($evento) {
            'daily.iniciado' => "Arrancó el Daily{$tipo()} de {$mes()} ".match ($d['desde'] ?? '') {
                'anterior' => 'copiando el mes anterior',
                'datos' => 'con las campañas que ya tenían gasto',
                default => 'vacío',
            }.'.',
            'daily.plan' => 'Cambió '.$this->cambios($d)." del Daily{$tipo()} de {$mes()}.",
            'daily.campania_agregada' => "Agregó {$camp()} al Daily{$tipo()} de {$mes()}.",
            'daily.campania_editada' => 'Cambió '.$this->cambios($d)." de {$camp()} en el Daily.",
            'daily.campania_quitada' => "Quitó {$camp()} del Daily.",
            'control.revision' => (($d['revisada'] ?? false) ? 'Marcó como revisada' : 'Quitó la marca de revisada a').' la alerta de '.$camp().' del '.self::fecha($d['fecha'] ?? null).'.',
            'control.campania_quitada' => "Quitó {$camp()} de Control de gasto".(! empty($d['motivo']) ? ": \"{$d['motivo']}\"" : '').'.',
            'control.campania_restaurada' => "Restauró {$camp()} en Control de gasto.",
            'fuentes.importadas' => 'Importó AF data del '.self::fecha($d['desde'] ?? null).' al '.self::fecha($d['hasta'] ?? null).' ('.number_format((int) ($d['filas'] ?? 0)).' filas).',
            'medios.data_medios_importada' => 'Importó data.medios del '.self::fecha($d['desde'] ?? null).' al '.self::fecha($d['hasta'] ?? null).' ('.number_format((int) ($d['filas'] ?? 0)).' filas).',
            'medios.gasto_manual' => 'Capturó gasto de '.($d['medio'] ?? 'un medio').' del '.self::fecha($d['fecha'] ?? null).': $'.number_format((float) ($d['gasto'] ?? 0), 2).'.',
            'medios.gasto_manual_borrado' => 'Borró un gasto capturado a mano.',
            'fuentes.venta_real' => 'Capturó la venta real '.(($d['tipo'] ?? '') === 'semana' ? 'de la semana del '.self::fecha($d['clave'] ?? null) : 'de '.self::mes($d['clave'] ?? '')).': '.
                'órdenes '.($d['ordenes'] ?? '—').', NC '.($d['nc'] ?? '—').'.',
            'datos.mes_eliminado' => "Borró los datos de {$mes()}.",
            default => $evento,
        };
    }

    /** "el budget a $500 y el KPI de installs a 800" */
    private function cambios(array $d): string
    {
        $partes = [];
        foreach ($d as $campo => $valor) {
            if ($campo === 'ajuste' && is_array($valor)) {
                $partes[] = 'el ajuste del '.(self::DIAS[$valor['dia'] ?? 0] ?? 'día').' a '.self::valor('budget', $valor['valor'] ?? null);
            } elseif ($campo === 'total_dia' && is_array($valor)) {
                $partes[] = 'el total a gastar del '.(self::DIAS[$valor['dia'] ?? 0] ?? 'día').' a '.self::valor('budget', $valor['valor'] ?? null);
            } elseif (isset(self::CAMPOS[$campo])) {
                $partes[] = self::CAMPOS[$campo].' a '.self::valor($campo, $valor);
            }
        }

        return $partes ? implode(', ', $partes) : 'datos';
    }

    private static function valor(string $campo, mixed $v): string
    {
        if ($v === null || $v === '') {
            return 'vacío';
        }
        if (in_array($campo, ['budget', 'daily_plataforma'], true)) {
            return '$'.number_format((float) $v, 2);
        }
        if (str_starts_with($campo, 'kpi_') || str_starts_with($campo, 'meta_')) {
            return number_format((float) $v);
        }
        if (str_starts_with($campo, 'fecha_')) {
            return self::fecha((string) $v);
        }
        if ($campo === 'notas') {
            return '"'.mb_strimwidth((string) $v, 0, 80, '…').'"';
        }

        return (string) $v;
    }

    private static function fecha(?string $f): string
    {
        if (! $f) {
            return '—';
        }
        $d = Carbon::parse($f);

        return $d->day.' '.mb_substr(self::MESES[$d->month - 1], 0, 3).' '.$d->year;
    }

    private static function mes(string $m): string
    {
        return preg_match('/^(\d{4})-(\d{2})$/', $m, $x) ? self::MESES[(int) $x[2] - 1].' '.$x[1] : $m;
    }
}
