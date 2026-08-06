<?php

namespace App\Services\Ingesta;

/**
 * Puerto de dashboard/shared/parser-nombres.js (proyecto Node, referencia).
 * Clasifica plataforma (Meta/TikTok), funnel/tipo de cuenta y "arte" (nombre
 * común del creativo) directo de las columnas Campaign/Ad del CSV de
 * AppsFlyer. Clase pura -- sin dependencias de Eloquent/Laravel, sin I/O.
 *
 * Cada regla de acá tiene una razón de negocio encontrada con datos reales
 * (ver comentarios por método) -- no son arbitrarias, y varias corrigen bugs
 * reales que ya vivimos del lado Node. NO simplificar sin revisar el porqué.
 */
class ClasificadorNombres
{
    /**
     * Mapa campaña -> {funnel, tipoCuenta} de TikTok -- fuente de verdad
     * única, confirmada contra el Excel de referencia del negocio
     * (2026-07-18). Idéntico al de Node, sin cambios de patrones.
     */
    public const MAPA_CAMPANIA_TIKTOK = [
        ['patron' => 'AWARENESS-BROAD', 'funnel' => 'AWA', 'tipoCuenta' => 'DTC'],
        ['patron' => 'SHOPPING-AND-CATALOGO-RMK-TIKTOK-ONLY', 'funnel' => 'CNV', 'tipoCuenta' => 'DTC'],
        ['patron' => 'CONVERSION-AND-RMK-PURCHASE-TIKTOK-ONLY', 'funnel' => 'LOY', 'tipoCuenta' => 'DTC'],
        ['patron' => 'CONVERSION-AND-SMART', 'funnel' => 'CNV', 'tipoCuenta' => 'DTC'],
        ['patron' => 'INSTALL-AND-VOLUME', 'funnel' => 'CONS', 'tipoCuenta' => 'BRD'],
    ];

    /**
     * Mapa campaña -> funnel de Meta -- validado contra 9 campañas reales de
     * Data (5).csv (2026-07-30). Orden importa: patrones más específicos
     * primero (BI-, CONVERSION-AND-RMK/PURCHASE) para que no los tape un
     * patrón más genérico que también matchea por substring (ej.
     * "BI-ADVANTAGE-AND-SHOPPING..." también contiene "ADVANTAGE-AND-
     * SHOPPING", pero BI- debe ganar).
     *
     * El campo tipoCuenta de este mapa se conserva por compatibilidad de
     * forma con MAPA_CAMPANIA_TIKTOK pero YA NO SE USA para clasificar
     * DTC/BRD -- ver parsearNombre(), tipoCuenta sale siempre del segmento
     * explícito "_DTC_"/"_BRD_" en el nombre, nunca de acá (un patrón de
     * objetivo de campaña no implica DTC ni BRD por sí solo: hay campañas
     * BRD reales con ADVANTAGE-AND-SHOPPING, y DTC con INSTALL-AND-VOLUME).
     */
    public const MAPA_CAMPANIA_META = [
        ['patron' => 'BI-', 'funnel' => 'CONS', 'tipoCuenta' => 'BRD'],
        ['patron' => 'CONVERSION-AND-RMK', 'funnel' => 'LOY', 'tipoCuenta' => 'DTC'],
        ['patron' => 'PURCHASE', 'funnel' => 'LOY', 'tipoCuenta' => 'DTC'],
        ['patron' => 'AWARENESS', 'funnel' => 'AWA', 'tipoCuenta' => 'DTC'],
        ['patron' => 'ADVANTAGE-AND-SHOPPING', 'funnel' => 'CNV', 'tipoCuenta' => 'DTC'],
        ['patron' => 'CONVERSION', 'funnel' => 'CNV', 'tipoCuenta' => 'DTC'],
        ['patron' => 'INSTALL-AND-VOLUME', 'funnel' => 'CONS', 'tipoCuenta' => 'BRD'],
    ];

    private const TIPO_CUENTA_CODES_FALLBACK = ['DTC', 'BRD'];

    /**
     * El segmento "_CON_" NO es el nombre del funnel -- toda campaña real
     * con ese segmento es Install-and-Volume/Advantage-Install, la misma
     * etapa BRD que el mapa de patrones llama "CONS". Sin esta traducción,
     * el segmento "CON" se guardaba literal y pisaba el "CONS" correcto que
     * el patrón ya había resuelto (regresión real detectada al fijar el bug
     * de CNV/LOY de parsearNombre()). Los demás códigos son 1:1 (el
     * segmento ES el nombre del funnel).
     */
    private const FUNNEL_SEGMENTO_A_FUNNEL = ['AWA' => 'AWA', 'CNV' => 'CNV', 'CON' => 'CONS', 'LOY' => 'LOY'];

    /**
     * El "arte" es la parte de la nomenclatura DESPUÉS del rango de fechas
     * de campaña (ej. "...-VID-03JUL-31JUL-ACER-TADAS" -> arte
     * "ACER-TADAS"). El separador antes de las fechas varía (VID/MP/SP),
     * igual que el primer token de fecha.
     */
    private const ARTE_RE = '/-(?:VID|MP|SP)-\d{1,2}[A-Z]{0,4}-\d{1,2}[A-Z]{3,4}-(.+)$/i';

    private const EXT_VIDEO_RE = '/\.(mp4|mov)/i';

    /**
     * Formato del creativo -- pedido explícito del negocio (2026-08-04):
     * VID = Video; MP o CARO = Catálogo (Multi Producto); SP o IMG =
     * Imagen. Se busca en el Ad name CRUDO ($adRaw en parsearNombre()),
     * NUNCA en nombre_comun/adNameShort -- ese campo puede ya ser el arte
     * recortado (sin el token de formato, que vive ANTES del arte en la
     * nomenclatura), así que buscar ahí nunca encuentra nada. VID/MP/SP
     * viven en la parte con guiones del nombre (antes de la fecha, ver
     * ARTE_RE); CARO vive en el prefijo separado por "_" -- por eso el
     * segmento se busca partiendo por "_" Y "-", no solo uno de los dos.
     */
    private const FORMATO_CODES = [
        'VID' => 'VIDEO',
        'MP' => 'CATALOGO',
        'CARO' => 'CATALOGO',
        'SP' => 'IMAGEN',
        'IMG' => 'IMAGEN',
    ];

    private static function bloqueRegex(string $prefijo): string
    {
        // El código de bloque se busca "<PREFIJO>-<CODIGO>" en cualquier
        // parte de la cadena (precedido por inicio de cadena, "_" o "-"),
        // sin asumir dónde cae la extensión. Mismo criterio para FB- (Meta)
        // y TKT- (TikTok).
        return '/(?:^|[_-])'.$prefijo.'-([A-Za-z0-9-]+)/i';
    }

    /**
     * Decide a qué plataforma pertenece una fila del CSV unificado de
     * AppsFlyer. Busca el bloque FB-/TKT- primero en Campaign (más limpio y
     * consistente por campaña completa que Ad) y usa Ad como respaldo si
     * Campaign no lo trae. Sin bloque en ninguno de los dos -> null (CRM,
     * QR, otros DSP -- no son Meta ni TikTok pagado, nunca se adivina).
     */
    public static function clasificarPlataforma(?string $campaignRaw, ?string $adRaw): ?string
    {
        $campaign = (string) $campaignRaw;
        $ad = (string) $adRaw;

        if (preg_match(self::bloqueRegex('FB'), $campaign) || preg_match(self::bloqueRegex('FB'), $ad)) {
            return 'meta';
        }
        if (preg_match(self::bloqueRegex('TKT'), $campaign) || preg_match(self::bloqueRegex('TKT'), $ad)) {
            return 'tiktok';
        }

        return null;
    }

    /**
     * Dos bloques distintos, a propósito NO se colapsan en uno: el de
     * Campaign (con Ad como respaldo) sirve para funnel/tipoCuenta; el de
     * Ad específicamente sirve para arte -- confirmado contra datos reales:
     * el bloque de Campaign casi nunca trae el sufijo de fechas+arte
     * completo, usarlo para arte hacía fallar la extracción casi siempre.
     *
     * @return array{paraFunnel: ?string, paraArte: ?string}
     */
    private static function extraerBloques(string $plataforma, ?string $campaignRaw, ?string $adRaw): array
    {
        $prefijo = match ($plataforma) {
            'meta' => 'FB',
            'tiktok' => 'TKT',
            default => null,
        };

        if ($prefijo === null) {
            return ['paraFunnel' => null, 'paraArte' => null];
        }

        $regex = self::bloqueRegex($prefijo);
        preg_match($regex, (string) $campaignRaw, $mCampaign);
        preg_match($regex, (string) $adRaw, $mAd);

        return [
            'paraFunnel' => $mCampaign[1] ?? ($mAd[1] ?? null),
            'paraArte' => $mAd[1] ?? null,
        ];
    }

    /**
     * Busca el bloque de campaña contra el mapa de patrones de texto libre
     * de la plataforma (primer match gana, por eso el orden de los mapas
     * importa). Cualquier campaña que no calce con ningún patrón queda SIN
     * CLASIFICAR (funnel y tipoCuenta null) -- nunca se asigna un funnel al
     * azar.
     *
     * @return array{funnel: ?string, tipoCuenta: ?string, patron: ?string}
     */
    public static function clasificarCampania(?string $bloqueCampania, string $plataforma): array
    {
        $mapa = match ($plataforma) {
            'meta' => self::MAPA_CAMPANIA_META,
            'tiktok' => self::MAPA_CAMPANIA_TIKTOK,
            default => null,
        };

        $bloque = mb_strtoupper((string) $bloqueCampania);

        if ($mapa !== null) {
            foreach ($mapa as $entrada) {
                if (str_contains($bloque, $entrada['patron'])) {
                    return [
                        'funnel' => $entrada['funnel'],
                        'tipoCuenta' => $entrada['tipoCuenta'],
                        'patron' => $entrada['patron'],
                    ];
                }
            }
        }

        return ['funnel' => null, 'tipoCuenta' => null, 'patron' => null];
    }

    /**
     * Si no calza el patrón de fechas, null -- nunca se inventa agrupando
     * por otra cosa (ese ad entra al flujo de corrección manual).
     */
    public static function extraerArte(?string $bloqueCampania): ?string
    {
        if (preg_match(self::ARTE_RE, (string) $bloqueCampania, $m)) {
            return mb_strtoupper($m[1]);
        }

        return null;
    }

    private static function buscarSegmento(?string $texto, array $codigos): ?string
    {
        // Segmento COMPLETO separado por "_" (nunca substring -- "CON" no
        // matchea dentro de "CONTINUA").
        $segmentos = explode('_', mb_strtoupper((string) $texto));
        foreach ($segmentos as $seg) {
            if (in_array($seg, $codigos, true)) {
                return $seg;
            }
        }

        return null;
    }

    private static function buscarSegmentoFunnel(?string $texto): ?string
    {
        $seg = self::buscarSegmento($texto, array_keys(self::FUNNEL_SEGMENTO_A_FUNNEL));

        return $seg !== null ? self::FUNNEL_SEGMENTO_A_FUNNEL[$seg] : null;
    }

    private static function buscarFormato(?string $texto): ?string
    {
        $segmentos = preg_split('/[_-]/', mb_strtoupper((string) $texto));
        foreach ($segmentos as $seg) {
            if (isset(self::FORMATO_CODES[$seg])) {
                return self::FORMATO_CODES[$seg];
            }
        }

        return null;
    }

    /**
     * Fallback puro por segmento explícito (sin pasar por el mapa de
     * patrones) -- expuesto igual que en Node aunque parsearNombre() ya lo
     * usa internamente, por si se necesita el segmento solo.
     *
     * @return array{tipoCuenta: ?string, funnel: ?string}
     */
    public static function clasificarPorSegmentos(?string $campaignRaw, ?string $adRaw): array
    {
        return [
            'tipoCuenta' => self::buscarSegmento($campaignRaw, self::TIPO_CUENTA_CODES_FALLBACK)
                ?? self::buscarSegmento($adRaw, self::TIPO_CUENTA_CODES_FALLBACK),
            'funnel' => self::buscarSegmentoFunnel($campaignRaw) ?? self::buscarSegmentoFunnel($adRaw),
        ];
    }

    /**
     * Nombre de archivo de video embebido en el Ad name, si lo trae.
     */
    public static function extraerVideoFilename(?string $adRaw): ?string
    {
        $raw = (string) $adRaw;
        if (preg_match(self::EXT_VIDEO_RE, $raw, $m, PREG_OFFSET_CAPTURE)) {
            $finExt = $m[0][1] + strlen($m[0][0]);

            return substr($raw, 0, $finExt);
        }

        return null;
    }

    /**
     * Punto de entrada único: dado el Campaign/Ad name crudos de una fila ya
     * agregada por Ad ID, resuelve plataforma + funnel/tipoCuenta + arte en
     * un solo paso. arte === null es la condición que dispara el flujo de
     * corrección manual -- nunca se agrupa por una llave inventada.
     *
     * @return array{plataforma: ?string, funnel: ?string, tipoCuenta: ?string, patron: ?string, arte: ?string, videoFilename: ?string, formato: ?string}
     */
    public static function parsearNombre(?string $campaignRaw, ?string $adRaw): array
    {
        $plataforma = self::clasificarPlataforma($campaignRaw, $adRaw);
        if ($plataforma === null) {
            return [
                'plataforma' => null,
                'funnel' => null,
                'tipoCuenta' => null,
                'patron' => null,
                'arte' => null,
                'videoFilename' => null,
                'formato' => null,
            ];
        }

        $bloques = self::extraerBloques($plataforma, $campaignRaw, $adRaw);
        $bloqueClasificado = self::clasificarCampania($bloques['paraFunnel'], $plataforma);
        $patron = $bloqueClasificado['patron'];

        // tipoCuenta (DTC/BRD) tiene una sola fuente de verdad: el segmento
        // EXPLÍCITO "_DTC_"/"_BRD_" en Campaign (Ad como respaldo). El
        // tipoCuenta del mapa de patrones NUNCA se usa para esto. El
        // segmento explícito SIEMPRE gana; si no aparece, se asume DTC como
        // último recurso.
        $tipoCuenta = self::buscarSegmento($campaignRaw, self::TIPO_CUENTA_CODES_FALLBACK)
            ?? self::buscarSegmento($adRaw, self::TIPO_CUENTA_CODES_FALLBACK);

        // funnel: mismo bug real y misma fuente de verdad que tipoCuenta
        // arriba -- caso PILSENER-KIT-QUE-NECESITAS: dos campañas de Meta
        // con el MISMO texto libre "CONVERSION-AND-RMK" pero funnels
        // distintos por el segmento explícito (una "_CNV_", otra "_LOY_")
        // se colapsaban las dos en LOY porque el patrón de mapa (texto
        // libre, sin distinguir CNV de LOY) se consultaba ANTES que el
        // segmento. El segmento explícito SIEMPRE gana; el patrón de mapa
        // es el ÚLTIMO recurso.
        $funnel = self::buscarSegmentoFunnel($campaignRaw) ?? self::buscarSegmentoFunnel($adRaw);
        if ($funnel === null) {
            $funnel = $bloqueClasificado['funnel'];
        }

        if ($tipoCuenta === null) {
            $tipoCuenta = 'DTC';
        }

        return [
            'plataforma' => $plataforma,
            'funnel' => $funnel,
            'tipoCuenta' => $tipoCuenta,
            'patron' => $patron,
            'arte' => self::extraerArte($bloques['paraArte']),
            'videoFilename' => self::extraerVideoFilename($adRaw),
            // Formato busca en el Ad crudo completo, NO en $bloques['paraArte']
            // (que ya viene recortado por extraerBloques) -- el token de
            // formato puede vivir antes del bloque FB-/TKT- (ej. el prefijo
            // "CARO_...").
            'formato' => self::buscarFormato($adRaw),
        ];
    }
}
