<?php

namespace App\Services\Ingesta;

/**
 * Puerto de limpiarAppsFlyerAdId + parseCsvComillas (dashboard/shared/motor.js,
 * proyecto Node, referencia). Parsea el CSV de AppsFlyer (comillas con comas
 * embebidas, ej. "Jul 02, 2026") y agrega por Ad ID, sumando la serie diaria.
 * Clase pura -- sin dependencias de Eloquent/Laravel, sin I/O (recibe el
 * contenido del CSV ya leído como string, no un path de archivo).
 *
 * Solo porta el camino CSV -- parseTabla en Node también soporta un pegado
 * TSV legado (tab-delimited) que ya no se usa en la arquitectura actual; el
 * único input real hoy es el CSV único de AppsFlyer subido por el usuario.
 */
class AppsFlyerCsvParser
{
    /**
     * NC y Orders NUNCA salen de Meta/TikTok -- AppsFlyer es la única fuente
     * válida para estas dos métricas. A propósito no existe una ruta de
     * alias para otra fuente -- si se agrega "por si acaso", vuelve a
     * devolver 0 silencioso. No agregar sin confirmar con el negocio.
     */
    private const ALIAS_NC = [
        'Activity - Event Counter - First Order (sum)',
        'New Customer Activity - Event Counter - First Order (sum)',
    ];

    private const ALIAS_ORDERS = [
        'Activity - Event Counter - Order Submitted (sum)',
        'repurchase Activity - Event Counter - Order',
    ];

    private const MESES = [
        'Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
        'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08',
        'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12',
    ];

    /**
     * Punto de entrada único: parsea el CSV crudo y agrega por Ad ID, sumando
     * la serie diaria (installs/orders/newCustomers) de cada uno.
     *
     * @return array{
     *     limpias: list<array{adId: string, campaign: string, adRaw: string, serie: list<array{fecha: string, installs: int|float, orders: int|float, newCustomers: int|float}>}>,
     *     problemas: list<array{fuente: string, motivo: string, fila: array<string, string>}>,
     *     subtotales: int,
     *     organico: list<array{fecha: string, installs: int|float, orders: int|float, newCustomers: int|float}>,
     *     columnaNC: ?string,
     *     columnaOrders: ?string,
     * }
     */
    public static function parse(string $csvCrudo): array
    {
        $filas = self::parseCsvComillas($csvCrudo);
        $headers = array_keys($filas[0] ?? []);

        // Se resuelve UNA vez, no por fila -- columnaNC/columnaOrders viajan
        // en el resultado para que quien consuma esto sepa si la columna
        // realmente existe. Si no existe, el total final debe ser null,
        // nunca 0 -- eso lo decide quien consume este resultado, no acá.
        [$columnaNC, $extraerNC] = self::resolverExtractorMetrica($headers, self::ALIAS_NC);
        [$columnaOrders, $extraerOrders] = self::resolverExtractorMetrica($headers, self::ALIAS_ORDERS);

        $filasNormalizadas = [];
        $problemas = [];
        $subtotales = 0;

        foreach ($filas as $row) {
            if (self::esFilaDeSubtotal($row)) {
                $subtotales++;

                continue;
            }

            $fecha = self::limpiarFecha($row['Install Day'] ?? null);
            $adIdRaw = $row['Ad ID'] ?? null;

            // La columna sí existe pero ESTA fila en particular no parseó --
            // se flagea para revisar a mano y se usa 0 solo para esa fila
            // (no rompe la suma de las demás filas que sí parsearon bien).
            $ncRaw = $extraerNC($row);
            $ordersRaw = $extraerOrders($row);
            if ($columnaNC !== null && $ncRaw === null) {
                $problemas[] = [
                    'fuente' => 'AppsFlyer ad ID',
                    'motivo' => "NC (columna \"{$columnaNC}\") no se pudo interpretar: \"{$row[$columnaNC]}\"",
                    'fila' => $row,
                ];
            }
            if ($columnaOrders !== null && $ordersRaw === null) {
                $problemas[] = [
                    'fuente' => 'AppsFlyer ad ID',
                    'motivo' => "Orders (columna \"{$columnaOrders}\") no se pudo interpretar: \"{$row[$columnaOrders]}\"",
                    'fila' => $row,
                ];
            }
            $newCustomers = $ncRaw ?? 0;
            $orders = $ordersRaw ?? 0;

            if (self::esIdOrganico($adIdRaw)) {
                if ($fecha === null) {
                    $problemas[] = [
                        'fuente' => 'AppsFlyer ad ID',
                        'motivo' => 'Install Day no reconocido: "'.($row['Install Day'] ?? '').'"',
                        'fila' => $row,
                    ];

                    continue;
                }
                $filasNormalizadas[] = [
                    'adId' => null,
                    'esOrganico' => true,
                    'campaign' => '',
                    'adRaw' => '',
                    'fecha' => $fecha,
                    'installs' => self::normalizarNumero($row['Installs (sum)'] ?? null),
                    'orders' => $orders,
                    'newCustomers' => $newCustomers,
                    'filaOriginal' => $row,
                ];

                continue;
            }

            $adId = self::idToString($adIdRaw);
            if ($adId === null || $fecha === null) {
                $problemas[] = [
                    'fuente' => 'AppsFlyer ad ID',
                    'motivo' => $adId === null
                        ? 'Ad ID inválido o en notación científica'
                        : 'Install Day no reconocido: "'.($row['Install Day'] ?? '').'"',
                    'fila' => $row,
                ];

                continue;
            }

            // campaign/adRaw se guardan de la PRIMERA fila vista de este Ad
            // ID (un Ad ID pertenece a una sola campaña/nombre de ad -- no
            // cambia entre filas del mismo Ad ID, solo Install Day cambia).
            $filasNormalizadas[] = [
                'adId' => $adId,
                'esOrganico' => false,
                'campaign' => trim($row['Campaign'] ?? ''),
                'adRaw' => $row['Ad'] ?? '',
                'fecha' => $fecha,
                'installs' => self::normalizarNumero($row['Installs (sum)'] ?? null),
                'orders' => $orders,
                'newCustomers' => $newCustomers,
                'filaOriginal' => $row,
            ];
        }

        $agregado = self::agregarPorAdId($filasNormalizadas);

        return [
            'limpias' => $agregado['limpias'],
            'problemas' => [...$problemas, ...$agregado['problemasDuplicado']],
            'subtotales' => $subtotales,
            'organico' => $agregado['organico'],
            'columnaNC' => $columnaNC,
            'columnaOrders' => $columnaOrders,
        ];
    }

    /**
     * Agrega filas YA normalizadas (adId/fecha/installs/orders/newCustomers
     * ya validados por el llamador) por Ad ID, sumando la serie diaria --
     * agnóstico de si el dato vino de un CSV subido a mano (único llamador
     * hoy, ver parse() arriba) o de un pull en vivo a la API de AppsFlyer
     * (llamador futuro: un enriquecedor que arme su propia lista de filas
     * normalizadas desde el JSON de la API y llame a este mismo método).
     *
     * $sumarDuplicados=false (default, comportamiento CSV actual sin
     * cambios): una segunda fila con el mismo Ad ID+fecha pero valores
     * distintos se descarta y se flaggea como problema, se conserva la
     * primera vista. $sumarDuplicados=true: en vez de descartar, SUMA los
     * valores -- pensado para el caso en que la fuente legítimamente reporta
     * el mismo Ad ID+fecha más de una vez (ej. AppsFlyer con apps iOS+Android
     * combinadas en un mismo pull, cada una aportando installs/NC/orders
     * reales y distintos para el mismo Ad ID+fecha).
     *
     * @param  list<array{adId: ?string, esOrganico: bool, campaign: string, adRaw: string, fecha: string, installs: int|float, orders: int|float, newCustomers: int|float, filaOriginal: mixed}>  $filasNormalizadas
     * @return array{
     *     limpias: list<array{adId: string, campaign: string, adRaw: string, serie: list<array{fecha: string, installs: int|float, orders: int|float, newCustomers: int|float}>}>,
     *     organico: list<array{fecha: string, installs: int|float, orders: int|float, newCustomers: int|float}>,
     *     problemasDuplicado: list<array{fuente: string, motivo: string, fila: mixed}>,
     * }
     */
    public static function agregarPorAdId(array $filasNormalizadas, bool $sumarDuplicados = false): array
    {
        $porAdId = [];
        $filasVistas = [];
        $organico = [];
        $problemasDuplicado = [];

        foreach ($filasNormalizadas as $fila) {
            if ($fila['esOrganico']) {
                $organico[] = [
                    'fecha' => $fila['fecha'],
                    'installs' => $fila['installs'],
                    'orders' => $fila['orders'],
                    'newCustomers' => $fila['newCustomers'],
                ];

                continue;
            }

            $adId = $fila['adId'];
            $key = $adId.'__'.$fila['fecha'];
            if (isset($filasVistas[$key])) {
                $yaVista = $filasVistas[$key];
                $mismosValores = $yaVista['installs'] === $fila['installs']
                    && $yaVista['orders'] === $fila['orders']
                    && $yaVista['newCustomers'] === $fila['newCustomers'];
                if (! $mismosValores) {
                    if ($sumarDuplicados) {
                        $filasVistas[$key] = [
                            'installs' => $yaVista['installs'] + $fila['installs'],
                            'orders' => $yaVista['orders'] + $fila['orders'],
                            'newCustomers' => $yaVista['newCustomers'] + $fila['newCustomers'],
                        ];
                        foreach ($porAdId[$adId]['serie'] as &$punto) {
                            if ($punto['fecha'] === $fila['fecha']) {
                                $punto['installs'] += $fila['installs'];
                                $punto['orders'] += $fila['orders'];
                                $punto['newCustomers'] += $fila['newCustomers'];
                                break;
                            }
                        }
                        unset($punto);
                    } else {
                        $problemasDuplicado[] = [
                            'fuente' => 'AppsFlyer ad ID',
                            'motivo' => "Fila duplicada para Ad ID {$adId} en {$fila['fecha']} con valores distintos, se conserva la primera",
                            'fila' => $fila['filaOriginal'],
                        ];
                    }
                }

                continue;
            }
            $filasVistas[$key] = [
                'installs' => $fila['installs'],
                'orders' => $fila['orders'],
                'newCustomers' => $fila['newCustomers'],
            ];

            if (! isset($porAdId[$adId])) {
                $porAdId[$adId] = [
                    'adId' => $adId,
                    'campaign' => $fila['campaign'],
                    'adRaw' => $fila['adRaw'],
                    'serie' => [],
                ];
            }
            $porAdId[$adId]['serie'][] = [
                'fecha' => $fila['fecha'],
                'installs' => $fila['installs'],
                'orders' => $fila['orders'],
                'newCustomers' => $fila['newCustomers'],
            ];
        }

        foreach ($porAdId as &$acc) {
            usort(
                $acc['serie'],
                fn (array $a, array $b) => strcmp(self::fechaOrdenable($a['fecha']), self::fechaOrdenable($b['fecha']))
            );
        }
        unset($acc);

        return [
            'limpias' => array_values($porAdId),
            'organico' => $organico,
            'problemasDuplicado' => $problemasDuplicado,
        ];
    }

    /**
     * Máquina de estados char-por-char sobre el texto completo (no
     * pre-separado por línea) porque un campo entre comillas podría, en
     * teoría, traer un salto de línea literal. \r se descarta antes de
     * arrancar -- mismo criterio que parseTabla en Node (clean =
     * text.replace(/\r/g, '')), AppsFlyer exporta con fin de línea CRLF.
     *
     * El BOM UTF-8 (\xEF\xBB\xBF) también se descarta antes de arrancar --
     * confirmado con datos reales (Data (5).csv trae BOM al inicio): del
     * lado Node esto "funcionaba solo" porque String.prototype.trim() de
     * JS SÍ incluye el BOM (U+FEFF) en su definición de whitespace, así que
     * headers.map(h => h.trim()) lo limpiaba de casualidad en el primer
     * header. trim() de PHP NO lo hace (solo espacio/tab/salto de línea/
     * null/vertical-tab) -- sin este strip explícito, la primera columna
     * del header quedaba con el BOM pegado y esa columna entera (acá,
     * "Campaign") nunca hacía match por nombre en ninguna fila.
     *
     * public (2026-08-27, ver plan del selector de fecha) -- mismo parser
     * reutilizado por SnowflakeCsvParser, para no duplicar esta máquina de
     * estados (BOM/CRLF/comillas con comas embebidas) en el pipeline diario.
     * Asume la fila 1 como header -- para el export de Snowflake (que trae
     * filas de resumen/título ANTES del header real), usar partirFilas()
     * directo y ubicar el header a mano, ver SnowflakeCsvParser.
     *
     * @return list<array<string, string>>
     */
    public static function parseCsvComillas(string $texto): array
    {
        $rows = self::partirFilas($texto);
        $headers = array_map('trim', array_shift($rows) ?? []);
        $rows = self::filasKeyed($rows, $headers);

        return $rows;
    }

    /**
     * Solo la máquina de estados -- filas crudas (list<list<string>>, sin
     * asociar a ningún header), BOM/CRLF ya descartados. Público para que
     * SnowflakeCsvParser pueda ubicar su propio header (no está en la fila
     * 1 en ese export, ver docblock de parseCsvComillas) antes de asociar
     * columnas.
     *
     * @return list<list<string>>
     */
    public static function partirFilas(string $texto): array
    {
        $texto = str_replace("\r", '', $texto);
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);

        $rows = [];
        $row = [];
        $field = '';
        $inQuotes = false;
        $len = strlen($texto);

        for ($i = 0; $i < $len; $i++) {
            $c = $texto[$i];
            if ($inQuotes) {
                if ($c === '"') {
                    if (($texto[$i + 1] ?? null) === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    $field .= $c;
                }
            } elseif ($c === '"') {
                $inQuotes = true;
            } elseif ($c === ',') {
                $row[] = $field;
                $field = '';
            } elseif ($c === "\n") {
                $row[] = $field;
                $field = '';
                $rows[] = $row;
                $row = [];
            } else {
                $field .= $c;
            }
        }
        if (strlen($field) > 0 || count($row) > 0) {
            $row[] = $field;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Asocia filas crudas (partirFilas()) a un header ya elegido -- extraído
     * de parseCsvComillas para que SnowflakeCsvParser lo reuse con SU propio
     * header (no necesariamente la fila 1).
     *
     * @param  list<list<string>>  $rows
     * @param  list<string>  $headers
     * @return list<array<string, string>>
     */
    public static function filasKeyed(array $rows, array $headers): array
    {

        $out = [];
        foreach ($rows as $r) {
            if (! self::algunValorNoVacio($r)) {
                continue;
            }
            $fila = [];
            foreach ($headers as $i => $h) {
                $fila[$h] = trim($r[$i] ?? '');
            }
            $out[] = $fila;
        }

        return $out;
    }

    private static function algunValorNoVacio(array $r): bool
    {
        foreach ($r as $v) {
            if ($v !== '') {
                return true;
            }
        }

        return false;
    }

    private static function normalizarNombreColumna(string $nombre): string
    {
        // \u{00A0} (espacio de no separación) aparece en algunos headers de
        // AppsFlyer copiados desde su UI web -- se normaliza a espacio
        // regular ANTES de comparar; si no, la columna nunca hace match por
        // alias aunque el texto se vea idéntico a simple vista.
        $limpio = str_replace("\u{00A0}", ' ', $nombre);
        $limpio = trim($limpio);
        $limpio = preg_replace('/\s+/', ' ', $limpio);

        return mb_strtolower($limpio);
    }

    private static function buscarColumnaPorAlias(array $headers, array $alias): ?string
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

    private static function parsearNumeroConSeparadores(?string $raw): int|float|null
    {
        if ($raw === null) {
            return 0;
        }
        $str = trim($raw);
        if ($str === '') {
            return 0;
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $str)) {
            return $str + 0;
        }
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $str)) {
            return str_replace(',', '', $str) + 0;
        }
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $str)) {
            return str_replace(',', '.', str_replace('.', '', $str)) + 0;
        }
        if (preg_match('/^-?\d+,\d+$/', $str)) {
            return str_replace(',', '.', $str) + 0;
        }

        return null;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $alias
     * @return array{0: ?string, 1: callable(array<string,string>): (int|float|null)}
     */
    private static function resolverExtractorMetrica(array $headers, array $alias): array
    {
        $columna = self::buscarColumnaPorAlias($headers, $alias);

        return [
            $columna,
            fn (array $row) => $columna === null ? null : self::parsearNumeroConSeparadores($row[$columna] ?? null),
        ];
    }

    private static function limpiarFecha(?string $raw): ?string
    {
        $str = trim((string) $raw);
        if (! preg_match('/^(\w{3})\s+(\d{1,2}),\s+(\d{4})$/', $str, $m)) {
            return null;
        }
        [, $mes, $dia, $anio] = $m;
        if (! isset(self::MESES[$mes])) {
            return null;
        }

        return str_pad($dia, 2, '0', STR_PAD_LEFT).'-'.self::MESES[$mes].'-'.$anio;
    }

    private static function fechaOrdenable(string $fecha): string
    {
        return implode('-', array_reverse(explode('-', $fecha)));
    }

    /**
     * Reducido respecto al de Node: acá el input siempre es una celda de CSV
     * (string), nunca un número JS -- se omite esa rama (era para un caso de
     * Ad ID llegando como Number de una fuente distinta al CSV).
     */
    private static function idToString(?string $raw): ?string
    {
        $str = trim((string) $raw);
        if (preg_match('/^\d+(\.\d+)?[eE][+-]?\d+$/', $str)) {
            return null;
        }
        if (! preg_match('/^\d+$/', $str)) {
            return null;
        }

        return $str;
    }

    private static function normalizarNumero(?string $raw): int|float
    {
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return 0;
        }

        return $raw + 0;
    }

    private static function esFilaDeSubtotal(array $row): bool
    {
        return ! isset($row['Install Day']) || trim($row['Install Day']) === '';
    }

    private static function esIdOrganico(?string $rawId): bool
    {
        $str = trim((string) $rawId);

        return $str === 'None' || $str === '0';
    }
}
