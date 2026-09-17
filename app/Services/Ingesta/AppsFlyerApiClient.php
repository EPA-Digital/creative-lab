<?php

namespace App\Services\Ingesta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente mínimo sobre el Master API (Customizable Aggregated Data API) de
 * AppsFlyer -- verificado en vivo 2026-08-11 contra la cuenta real de
 * Ecuador (app iOS id1596944067 + Android ec.com.fiestacerca), no asumido:
 *
 * - Auth: header "Authorization: Bearer <token>" (el token de este proyecto
 *   es un JWE de 5 partes -- formato V2 de AppsFlyer). NUNCA query param.
 * - Base URL: https://hq1.appsflyer.com/api/master-agg-data/v4/app/{app_id}
 *   -- NO los reports fijos (partners_by_date_report/daily_report), esos no
 *   soportan breakdown por Ad/Ad ID. groupings=c,af_c_id,af_ad,af_ad_id,
 *   install_time reproduce exactamente Campaign/Campaign ID/Ad/Ad ID/Install
 *   Day del CSV manual.
 * - Los KPIs de First Order/Order Submitted NO son "first_order"/
 *   "order_submitted" como sugiere el nombre de columna del CSV (ese nombre
 *   es un relabel de Excel/Supermetrics) -- son los NOMBRES REALES de evento
 *   configurados en el SDK de la app: "First Order Placed" y "Order Placed"
 *   (confirmado inspeccionando /raw-data/.../in_app_events_report/v5 para
 *   descubrir los event_name reales, luego validado con
 *   event_counter_<nombre exacto> devolviendo NC siempre <= Orders por fila,
 *   como corresponde). Formato de KPI: "event_counter_<nombre del evento>",
 *   con el nombre tal cual (incluye espacios, sin encodear a mano -- Http::get
 *   ya arma la query string).
 * - install_time devuelve fecha YYYY-MM-DD (mucho más simple que el texto
 *   "Jul 02, 2026" del CSV) -- normalizarFecha() de EnriquecedorAppsFlyerApi
 *   la convierte a DD-MM-YYYY para calzar con el resto del pipeline.
 * - Sin paginación necesaria en el volumen real probado (1889 filas para un
 *   mes completo de la app Android) -- si un país mucho más grande empieza a
 *   truncar, la API devuelve un aviso en el body, no un error HTTP; no hay
 *   evidencia de eso todavía así que no se implementa "por si acaso".
 *
 * Respuesta siempre CSV (no hay header Accept que la cambie a JSON en esta
 * versión del Master API, verificado).
 */
class AppsFlyerApiClient
{
    private const BASE_URL = 'https://hq1.appsflyer.com/api/master-agg-data/v4/app';

    public function __construct(private readonly string $apiToken) {}

    public static function fromConfig(): self
    {
        $token = config('services.appsflyer.api_token');
        if (! $token) {
            throw new RuntimeException('Falta APPSFLYER_API_TOKEN en el entorno.');
        }

        return new self($token);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function get(string $appId, array $params): string
    {
        $respuesta = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiToken,
        ])->retry(4, fn (int $intento) => [1000, 3000, 8000][$intento - 1] ?? 8000, function ($exception) {
            return $exception instanceof ConnectionException
                || ($exception instanceof RequestException && in_array($exception->response->status(), [429, 500, 502, 503, 504], true));
        }, throw: false)->get(self::BASE_URL."/{$appId}", $params);

        if (! $respuesta->successful()) {
            throw new RuntimeException("AppsFlyer Master API (app {$appId}) respondió {$respuesta->status()}: {$respuesta->body()}");
        }

        return $respuesta->body();
    }
}
