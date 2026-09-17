<?php

namespace App\Services\Ingesta;

use App\Models\Creativo;
use App\Models\EstadoCreativoEvento;
use App\Models\Pais;
use Carbon\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Sincroniza el historial REAL de estado de los ads (2026-08-28, pedido
 * explícito) -- puerto del Google Apps Script "Historial de cambios Meta
 * Ads V9" que ya se usaba para México (activity log de la cuenta), acotado
 * a propósito a SOLO estado de anuncios -- el negocio pidió dejar afuera
 * presupuesto/creación/campaña de esta primera versión ("de aquí solo me
 * interesa lo de los ads").
 *
 * Por qué existe un job recurrente y no un pull bajo demanda: probado
 * contra la cuenta real de Ecuador (act_913224553013929/activities) --
 * pedir `since` de meses atrás IGUAL solo devuelve los eventos de los
 * últimos ~7 días, Meta no expone más historial que eso por este camino.
 * La única forma de tener un rango de fechas confiable a futuro
 * ("encendido del 1 feb al 5 mar") es ir guardando cada evento nosotros
 * mismos cada vez que esto corre (ver routes/console.php) -- nunca vamos a
 * poder reconstruir el pasado de un ad que ya corrió antes de que esto
 * exista.
 *
 * A diferencia del script de Apps Script -- que probaba cada object_id
 * como Ad, luego AdSet, luego Campaign para adivinar el nivel real --
 * acá no hace falta: el propio `event_type` YA dice el nivel sin
 * ambigüedad (update_ad_run_status es SIEMPRE un anuncio,
 * update_ad_set_run_status un conjunto, update_campaign_run_status una
 * campaña), así que un filtro simple alcanza.
 *
 * Vocabulario de estado MÁS AMPLIO que el script viejo a propósito (pedido
 * explícito): el script de México solo registraba el toggle binario
 * Activo<->Inactivo y descartaba cualquier otro estado -- acá se guarda
 * CUALQUIER valor que mande Meta, nunca se descarta. Confirmado contra
 * datos reales (2026-08-28, corrida real sobre Ecuador/México/Panamá):
 * Meta manda el texto YA localizado en extra_data.old_value/new_value
 * ("Activo", "Inactivo", "Procesamiento pendiente", "Revisión pendiente",
 * "Eliminado", "Actualización necesaria" -- vistos los 6 en cuentas reales
 * LATAM), no un enum crudo en inglés -- no hace falta traducir en la capa
 * de presentación, ya viene legible.
 */
class MetaEstadoAdsSyncService
{
    // Mismo tope que el script de Apps Script (maxPaginas=25) -- una
    // ventana de ~7 días de actividad de cuenta no debería necesitar
    // tantas páginas de 500, es un techo de seguridad, no lo normal.
    private const PAGINAS_MAX = 25;

    private const EVENT_TYPE_AD = 'update_ad_run_status';

    public function __construct(private readonly MetaApiClient $meta) {}

    /**
     * @return array{eventosNuevos: int, resueltosACreativo: int, sinCreativo: int}
     */
    public function sincronizar(string $paisSlug): array
    {
        $config = config("paises.{$paisSlug}");
        if (! $config || ! ($config['meta_ad_account_id'] ?? null)) {
            throw new InvalidArgumentException("País \"{$paisSlug}\" no tiene meta_ad_account_id configurado en config/paises.php.");
        }
        $pais = Pais::where('codigo', $config['codigo'])->firstOrFail();

        // Desde el último evento ya guardado para este país, con 1 hora de
        // solape para no perder nada justo en el borde de la corrida
        // anterior (firstOrCreate de abajo dedupe cualquier repetido) -- si
        // nunca corrió, 7 días atrás (Meta no da más que eso igual, ver
        // docblock de la clase).
        $ultimo = EstadoCreativoEvento::where('pais_id', $pais->id)->max('evento_en');
        $desde = $ultimo ? Carbon::parse($ultimo)->subHour() : now()->subDays(7);

        $actividades = $this->obtenerActividades($config['meta_ad_account_id'], $desde, now());

        $eventosNuevos = 0;
        $resueltos = 0;
        $sinCreativo = 0;

        // ad_id -> creativo_id, una sola consulta para toda la tanda en vez
        // de un SELECT por evento (puede haber cientos en 7 días de cuenta
        // activa).
        $adIds = array_values(array_unique(array_map(
            fn (array $act) => (string) ($act['object_id'] ?? ''),
            array_filter($actividades, fn (array $act) => ($act['event_type'] ?? null) === self::EVENT_TYPE_AD),
        )));
        $creativoIdPorAdId = Creativo::where('pais_id', $pais->id)
            ->whereIn('ad_id', $adIds)
            ->pluck('id', 'ad_id');

        foreach ($actividades as $act) {
            if (($act['event_type'] ?? null) !== self::EVENT_TYPE_AD) {
                continue;
            }
            $adId = (string) ($act['object_id'] ?? '');
            $eventoEn = $this->parsearFecha($act['event_time'] ?? null);
            if ($adId === '' || ! $eventoEn) {
                continue;
            }

            $extra = $this->parsearExtra($act['extra_data'] ?? null);
            // old_value/new_value es lo que realmente manda update_ad_run_status
            // (confirmado 2026-08-28 contra Ecuador/México/Panamá reales, ya
            // localizado: "Activo"/"Inactivo"/"Procesamiento pendiente"/etc.)
            // -- old_status/new_status queda como alias defensivo por si algún
            // event_type hermano (ad set/campaign) usa otro nombre; nunca se
            // inventa un estado si ninguno de los dos existe.
            $estadoAnterior = $extra['old_value'] ?? $extra['old_status'] ?? null;
            $estadoNuevo = $extra['new_value'] ?? $extra['new_status'] ?? null;
            if ($estadoNuevo === null) {
                continue;
            }

            $creativoId = $creativoIdPorAdId[$adId] ?? null;
            $creativoId ? $resueltos++ : $sinCreativo++;

            $evento = EstadoCreativoEvento::firstOrCreate(
                [
                    'pais_id' => $pais->id,
                    'ad_id' => $adId,
                    'evento_en' => $eventoEn,
                    'estado_nuevo' => $estadoNuevo,
                ],
                [
                    'creativo_id' => $creativoId,
                    'estado_anterior' => $estadoAnterior,
                    'actor_name' => $act['actor_name'] ?? null,
                    'extra_data' => $extra,
                ]
            );
            if ($evento->wasRecentlyCreated) {
                $eventosNuevos++;
            }
        }

        return ['eventosNuevos' => $eventosNuevos, 'resueltosACreativo' => $resueltos, 'sinCreativo' => $sinCreativo];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function obtenerActividades(string $adAccountId, Carbon $desde, Carbon $hasta): array
    {
        $actividades = [];
        $after = null;

        for ($pagina = 0; $pagina < self::PAGINAS_MAX; $pagina++) {
            $params = [
                'fields' => 'event_time,event_type,translated_event_type,actor_name,object_id,object_name,object_type,extra_data',
                'since' => $desde->timestamp,
                'until' => $hasta->timestamp,
                'limit' => '500',
            ];
            if ($after) {
                $params['after'] = $after;
            }

            try {
                $data = $this->meta->get("act_{$adAccountId}/activities", $params);
            } catch (Throwable $e) {
                // Best-effort, igual que EnriquecedorCostosMeta con la
                // segunda pasada de imagen -- si una página falla, se
                // conserva lo ya juntado en vez de perder toda la corrida
                // por un timeout puntual. El próximo sync retoma desde el
                // último evento guardado, así que nada se pierde para
                // siempre.
                break;
            }

            $actividades = array_merge($actividades, $data['data'] ?? []);

            $after = ! empty($data['paging']['next']) ? ($data['paging']['cursors']['after'] ?? null) : null;
            if (! $after) {
                break;
            }
        }

        return $actividades;
    }

    private function parsearFecha(mixed $eventTime): ?Carbon
    {
        if (! $eventTime) {
            return null;
        }
        try {
            return Carbon::parse($eventTime);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parsearExtra(mixed $extraData): array
    {
        if (is_array($extraData)) {
            return $extraData;
        }
        if (is_string($extraData)) {
            $decoded = json_decode($extraData, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
