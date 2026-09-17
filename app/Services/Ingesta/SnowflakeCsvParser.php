<?php

namespace App\Services\Ingesta;

/**
 * Parser del export de Snowflake (2026-08-27, ver plan del selector de
 * fecha) -- a diferencia de AppsFlyerCsvParser (una fila por Ad ID, con una
 * `serie` diaria agrupada adentro), acá CADA FILA ya es un punto diario
 * independiente (un Ad ID puede aparecer en muchas filas, una por día) --
 * no hace falta agrupar nada, solo limpiar/tipar cada fila.
 *
 * Reusa AppsFlyerCsvParser::parseCsvComillas (misma máquina de estados
 * BOM/CRLF/comillas) en vez de duplicarla.
 *
 * nc_real/orders_real salen TAL CUAL de las columnas "NC REALES PAID"/
 * "ORDENES REALES PAID" del CSV -- ya vienen prorrateadas por fila desde el
 * sheet del usuario, nunca se recalculan acá (ver VentaRealYAgrupacion,
 * que es la única fuente de verdad para el pipeline MENSUAL, no este).
 *
 * Nombres de columna exactos por confirmar contra el archivo real (visto
 * hasta ahora solo en captura de pantalla) -- por eso alias por columna,
 * mismo criterio que AppsFlyerCsvParser::ALIAS_NC/ALIAS_ORDERS, tolerante a
 * variaciones menores sin necesitar deploy.
 */
class SnowflakeCsvParser
{
    private const ALIAS_FECHA = ['Date', 'Fecha'];

    private const ALIAS_CAMPAIGN = ['Campaign name', 'Campaign'];

    private const ALIAS_AD = ['Ad name', 'Ad'];

    private const ALIAS_AD_ID = ['Ad ID'];

    private const ALIAS_IMPRESSIONS = ['Impressions'];

    private const ALIAS_CLICKS = ['Link clicks', 'Clicks'];

    private const ALIAS_COST = ['Cost'];

    private const ALIAS_INSTALLS = ['INSTALLS', 'Installs'];

    private const ALIAS_NC = ['NEW CUSTOMERS', 'New Customers', 'New Customer'];

    private const ALIAS_REPURCHASES = ['REPURCHASES', 'Repurchases'];

    private const ALIAS_ORDERS = ['ORDERS', 'Orders'];

    private const ALIAS_NC_REAL = ['NC REALES PAID', 'NC Real', 'NC_REAL'];

    private const ALIAS_ORDERS_REAL = ['ORDENES REALES PAID', 'ORDERS REALES PAID', 'Orders Real', 'ORDERS_REAL'];

    private const ALIAS_THUMBNAIL = ['Ad creative thumbnail URL', 'creative thumbnail', 'creative thumbnail url', 'thumbnail'];

    /**
     * @return array{
     *     filas: list<array{adId: string, fecha: string, campaign: string, adRaw: string, impressions: int, clicks: int, cost: float, installs: int, nc: int, repurchases: int, orders: int, ncReal: ?float, ordersReal: ?float, thumbnailUrl: ?string}>,
     *     problemas: list<array{motivo: string, fila: array<string, string>}>,
     * }
     */
    public static function parse(string $csvCrudo): array
    {
        $filasCrudas = AppsFlyerCsvParser::partirFilas($csvCrudo);
        $indiceHeader = self::ubicarFilaHeader($filasCrudas);
        if ($indiceHeader === null) {
            return [
                'filas' => [],
                'problemas' => [['motivo' => 'No se encontró una fila de header reconocible (con columnas de fecha y Ad ID) en las primeras filas del archivo.', 'fila' => []]],
            ];
        }

        $headers = array_map('trim', $filasCrudas[$indiceHeader]);
        $filas = AppsFlyerCsvParser::filasKeyed(array_slice($filasCrudas, $indiceHeader + 1), $headers);

        $colFecha = self::buscarColumna($headers, self::ALIAS_FECHA);
        $colCampaign = self::buscarColumna($headers, self::ALIAS_CAMPAIGN);
        $colAd = self::buscarColumna($headers, self::ALIAS_AD);
        $colAdId = self::buscarColumna($headers, self::ALIAS_AD_ID);
        $colImpressions = self::buscarColumna($headers, self::ALIAS_IMPRESSIONS);
        $colClicks = self::buscarColumna($headers, self::ALIAS_CLICKS);
        $colCost = self::buscarColumna($headers, self::ALIAS_COST);
        $colInstalls = self::buscarColumna($headers, self::ALIAS_INSTALLS);
        $colNc = self::buscarColumna($headers, self::ALIAS_NC);
        $colRepurchases = self::buscarColumna($headers, self::ALIAS_REPURCHASES);
        $colOrders = self::buscarColumna($headers, self::ALIAS_ORDERS);
        $colNcReal = self::buscarColumna($headers, self::ALIAS_NC_REAL);
        $colOrdersReal = self::buscarColumna($headers, self::ALIAS_ORDERS_REAL);
        $colThumbnail = self::buscarColumna($headers, self::ALIAS_THUMBNAIL);

        $filasNormalizadas = [];
        $problemas = [];

        foreach ($filas as $row) {
            $fecha = self::limpiarFecha($colFecha ? ($row[$colFecha] ?? null) : null);
            $adId = self::idToString($colAdId ? ($row[$colAdId] ?? null) : null);

            if ($adId === null || $fecha === null) {
                $problemas[] = [
                    'motivo' => $adId === null
                        ? 'Ad ID inválido, vacío o en notación científica'
                        : 'Fecha no reconocida: "'.($colFecha ? ($row[$colFecha] ?? '') : '').'"',
                    'fila' => $row,
                ];

                continue;
            }

            $filasNormalizadas[] = [
                'adId' => $adId,
                'fecha' => $fecha,
                'campaign' => trim($colCampaign ? ($row[$colCampaign] ?? '') : ''),
                'adRaw' => trim($colAd ? ($row[$colAd] ?? '') : ''),
                'impressions' => (int) round(self::parsearNumero($colImpressions ? ($row[$colImpressions] ?? null) : null) ?? 0),
                'clicks' => (int) round(self::parsearNumero($colClicks ? ($row[$colClicks] ?? null) : null) ?? 0),
                'cost' => self::parsearNumero($colCost ? ($row[$colCost] ?? null) : null) ?? 0.0,
                'installs' => (int) round(self::parsearNumero($colInstalls ? ($row[$colInstalls] ?? null) : null) ?? 0),
                'nc' => (int) round(self::parsearNumero($colNc ? ($row[$colNc] ?? null) : null) ?? 0),
                'repurchases' => (int) round(self::parsearNumero($colRepurchases ? ($row[$colRepurchases] ?? null) : null) ?? 0),
                'orders' => (int) round(self::parsearNumero($colOrders ? ($row[$colOrders] ?? null) : null) ?? 0),
                'ncReal' => $colNcReal ? self::parsearNumero($row[$colNcReal] ?? null) : null,
                'ordersReal' => $colOrdersReal ? self::parsearNumero($row[$colOrdersReal] ?? null) : null,
                'thumbnailUrl' => $colThumbnail ? (trim($row[$colThumbnail] ?? '') ?: null) : null,
            ];
        }

        return [
            'filas' => $filasNormalizadas,
            'problemas' => $problemas,
        ];
    }

    /**
     * El export real de Snowflake trae filas de resumen/título (totales,
     * "FORMULADO/SNOWFLAKE/APPSFLYER" como section-labels) ANTES del header
     * real -- confirmado contra el archivo real de Panamá (2026-08-27): el
     * header vive en la fila 4, no en la 1. Se escanean las primeras filas
     * buscando la que tenga, como valor de celda, tanto un alias de fecha
     * como un alias de Ad ID -- esa es el header real. Tope de 20 filas
     * (nunca escanea el archivo completo buscando esto) -- un CSV sin
     * summary rows (header en la fila 1) también matchea acá sin problema.
     */
    private static function ubicarFilaHeader(array $filasCrudas): ?int
    {
        $limite = min(count($filasCrudas), 20);
        for ($i = 0; $i < $limite; $i++) {
            $celdas = array_map([self::class, 'normalizarNombreColumna'], $filasCrudas[$i]);
            $tieneFecha = self::algunoEnLista($celdas, self::ALIAS_FECHA);
            $tieneAdId = self::algunoEnLista($celdas, self::ALIAS_AD_ID);
            if ($tieneFecha && $tieneAdId) {
                return $i;
            }
        }

        return null;
    }

    private static function algunoEnLista(array $celdasNormalizadas, array $alias): bool
    {
        $aliasNormalizados = array_map([self::class, 'normalizarNombreColumna'], $alias);

        return count(array_intersect($celdasNormalizadas, $aliasNormalizados)) > 0;
    }

    private static function buscarColumna(array $headers, array $alias): ?string
    {
        $normalizados = [];
        foreach ($headers as $h) {
            $normalizados[self::normalizarNombreColumna($h)] = $h;
        }
        foreach ($alias as $a) {
            $clave = self::normalizarNombreColumna($a);
            if (array_key_exists($clave, $normalizados)) {
                return $normalizados[$clave];
            }
        }

        return null;
    }

    private static function normalizarNombreColumna(string $nombre): string
    {
        $limpio = str_replace("\u{00A0}", ' ', $nombre);
        $limpio = trim($limpio);
        $limpio = preg_replace('/\s+/', ' ', $limpio);

        return mb_strtolower($limpio);
    }

    /**
     * Tolera "$1,028.00" (signo de moneda + separador de miles) además de
     * los formatos que ya cubre AppsFlyerCsvParser -- el export de
     * Snowflake trae Cost con "$" al frente (confirmado en la captura
     * compartida por el usuario).
     */
    private static function parsearNumero(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        $str = trim($raw);
        if ($str === '') {
            return null;
        }
        $str = ltrim($str, '$');
        $str = str_replace(',', '', $str);
        if (! is_numeric($str)) {
            return null;
        }

        return (float) $str;
    }

    /**
     * Fecha ISO (YYYY-MM-DD, confirmado en la captura compartida) --
     * distinto del formato "Feb 11, 2026" de AppsFlyerCsvParser.
     */
    private static function limpiarFecha(?string $raw): ?string
    {
        $str = trim((string) $raw);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $str, $m)) {
            return null;
        }

        return $str;
    }

    private static function idToString(?string $raw): ?string
    {
        $str = trim((string) $raw);
        if ($str === '') {
            return null;
        }
        if (preg_match('/^\d+(\.\d+)?[eE][+-]?\d+$/', $str)) {
            return null;
        }
        if (! preg_match('/^\d+$/', $str)) {
            return null;
        }

        return $str;
    }
}
