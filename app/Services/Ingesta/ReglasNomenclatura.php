<?php

namespace App\Services\Ingesta;

use App\Models\Cuenta;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reglas de clasificación de UNA cuenta (multi-cuenta, 2026-11-04). Cada
 * equipo le enseña al sistema su propio vocabulario al dar de alta la
 * cuenta (pedido explícito): con qué siglas marca performance o branding,
 * cuáles son SUS etapas de funnel y a qué equivalen, y ejemplos reales de
 * campañas, conjuntos y anuncios. La IA propone las reglas
 * (ProponerNomenclaturaService), una persona las revisa contra los
 * ejemplos, y aplicarlas es determinista: no llama a nadie.
 *
 * Las nomenclaturas no se pueden estandarizar, así que cada regla es una
 * lista "si coincide → valor", como IFS(REGEXMATCH(...)) en Sheets: por
 * siglas (palabra completa, "lead" no coincide dentro de "leads") o por
 * expresión regular. Gana la primera que coincide, buscando en la campaña,
 * luego en el conjunto y luego en el anuncio. Sin mayúsculas/minúsculas.
 *
 * Forma (versión 2):
 *   identificador: {siglas: [], patron}          ¿este nombre es de la cuenta?
 *   tipos_campania: [{nombre, eje: performance|branding|otro, siglas: [], patron}]
 *   funnel: [{nombre: "See", equivalente: AWA|CONS|CNV|LOY, siglas: [], patron}]
 *   campos.plataforma|formato: [{siglas: [], patron, valor}]
 *   dimensiones: [{clave, nombre, reglas: [{siglas: [], patron, valor}]}]
 *   arte: {catalogo: [{siglas, patron | post_id, valor}], regex}
 *     catalogo: el "Catálogo Creativos" de la cuenta (patrón → nombre del
 *       creativo), pegado de su hoja o armado desde la cola "Sin creativo"
 *       (post de TikTok → creativo). Gana la primera que coincide; las de
 *       post se revisan antes que los patrones.
 *     regex: respaldo, grupo 1 sobre el nombre del anuncio.
 *   usar_objetivo_plataforma: bool                funnel de respaldo (ver OBJETIVO_A_FUNNEL)
 *
 * Las etapas propias SIEMPRE tienen equivalente en la escala común: así
 * siguen funcionando colores, semáforo, agrupación de venta real y el
 * filtro de "sin clasificar" de los clientes. En pantalla se ve el nombre
 * propio ("Do"), no el código.
 *
 * DTC/BRD NO está acá: es propio de TaDa (BRD = marcas de BI, solo
 * Ecuador) y sigue saliendo de ClasificadorNombres. Performance/branding es
 * otro eje, común a todas las cuentas. Clase pura, sin I/O.
 */
class ReglasNomenclatura
{
    public const EQUIVALENTES = ['AWA', 'CONS', 'CNV', 'LOY'];

    public const EJES = ['performance', 'branding', 'otro'];

    public const FORMATOS = ['VIDEO', 'IMAGEN', 'CATALOGO'];

    public const MAX_DIMENSIONES = 6;

    public const MAX_TIPOS = 8;

    public const MAX_ETAPAS = 8;

    public const MAX_CATALOGO = 1000;

    /**
     * Respaldo del funnel cuando el nombre no lo dice (aprobado por el
     * equipo 2026-11-04): el objetivo de la campaña viene estandarizado de
     * la API, sin importar cómo nombre cada equipo. Loyalty nunca sale de
     * un objetivo -- ninguno lo distingue de conversión.
     */
    public const OBJETIVO_A_FUNNEL = [
        // Meta (objetivos ODAX y los anteriores)
        'OUTCOME_AWARENESS' => 'AWA', 'REACH' => 'AWA', 'BRAND_AWARENESS' => 'AWA',
        'OUTCOME_TRAFFIC' => 'CONS', 'OUTCOME_ENGAGEMENT' => 'CONS', 'VIDEO_VIEWS' => 'CONS', 'LINK_CLICKS' => 'CONS', 'POST_ENGAGEMENT' => 'CONS',
        'OUTCOME_LEADS' => 'CNV', 'OUTCOME_SALES' => 'CNV', 'OUTCOME_APP_PROMOTION' => 'CNV', 'CONVERSIONS' => 'CNV', 'APP_INSTALLS' => 'CNV', 'LEAD_GENERATION' => 'CNV',
        // TikTok
        'TRAFFIC' => 'CONS', 'ENGAGEMENT' => 'CONS',
        'APP_PROMOTION' => 'CNV', 'WEB_CONVERSIONS' => 'CNV', 'PRODUCT_SALES' => 'CNV',
    ];

    /**
     * Valida y deja las reglas en su forma canónica (v2). Las de la versión
     * anterior (token en posición) se convierten. Mensajes en español, para
     * mostrarlos tal cual en el formulario.
     *
     * @return array<string, mixed>
     */
    public static function normalizar(array $reglas): array
    {
        if ((int) ($reglas['version'] ?? 1) < 2) {
            $reglas = self::desdeVersion1($reglas);
        }

        $identificador = self::condicion($reglas['identificador'] ?? null, 'el identificador de la cuenta');

        $tipos = [];
        foreach (array_slice(self::lista($reglas['tipos_campania'] ?? []), 0, self::MAX_TIPOS) as $t) {
            $nombre = mb_substr(trim((string) ($t['nombre'] ?? '')), 0, 40);
            $condicion = self::condicion($t, "el tipo {$nombre}");
            if ($nombre === '' || ! $condicion) {
                continue;
            }
            $eje = mb_strtolower(trim((string) ($t['eje'] ?? 'otro')));
            if (! in_array($eje, self::EJES, true)) {
                throw new InvalidArgumentException("El tipo {$nombre} debe ser Performance, Branding u Otro.");
            }
            $tipos[] = ['nombre' => $nombre, 'eje' => $eje, ...$condicion];
        }

        $etapas = [];
        foreach (array_slice(self::lista($reglas['funnel'] ?? []), 0, self::MAX_ETAPAS) as $e) {
            $nombre = mb_substr(trim((string) ($e['nombre'] ?? '')), 0, 40);
            $condicion = self::condicion($e, "la etapa {$nombre}");
            if ($nombre === '' || ! $condicion) {
                continue;
            }
            $equivalente = mb_strtoupper(trim((string) ($e['equivalente'] ?? '')));
            if (! in_array($equivalente, self::EQUIVALENTES, true)) {
                throw new InvalidArgumentException("Elige a qué equivale la etapa {$nombre}: Awareness, Consideración, Conversión o Loyalty.");
            }
            $etapas[] = ['nombre' => $nombre, 'equivalente' => $equivalente, ...$condicion];
        }

        $campos = [
            'plataforma' => self::reglasConValor((array) ($reglas['campos']['plataforma'] ?? []), 'plataforma', array_keys(Cuenta::MEDIOS), mb_strtolower(...)),
            'formato' => self::reglasConValor((array) ($reglas['campos']['formato'] ?? []), 'formato', self::FORMATOS, mb_strtoupper(...)),
        ];

        $dimensiones = [];
        foreach (array_slice(self::lista($reglas['dimensiones'] ?? []), 0, self::MAX_DIMENSIONES) as $d) {
            $nombre = mb_substr(trim((string) ($d['nombre'] ?? '')), 0, 40);
            $lista = self::reglasConValor(is_array($d['reglas'] ?? null) ? $d['reglas'] : ($d['valores'] ?? null ? ['valores' => $d['valores']] : []), $nombre ?: 'la dimensión', null, fn ($v) => mb_substr($v, 0, 60));
            if ($nombre === '' || ! $lista) {
                continue;
            }
            $dimensiones[] = ['clave' => Str::slug($d['clave'] ?? $nombre, '_') ?: 'dimension', 'nombre' => $nombre, 'reglas' => $lista];
        }

        $catalogo = [];
        foreach (array_slice(self::lista($reglas['arte']['catalogo'] ?? []), 0, self::MAX_CATALOGO) as $r) {
            $valor = mb_substr(trim((string) ($r['valor'] ?? '')), 0, 120);
            if ($valor === '') {
                continue;
            }
            $postId = preg_replace('/\D/', '', (string) ($r['post_id'] ?? ''));
            if ($postId !== '') {
                $catalogo[] = ['post_id' => mb_substr($postId, 0, 32), 'valor' => $valor];

                continue;
            }
            $condicion = self::condicion($r, "el creativo {$valor}");
            if ($condicion) {
                $catalogo[] = [...$condicion, 'valor' => $valor];
            }
        }
        $regex = filled($reglas['arte']['regex'] ?? null) ? self::validarRegex((string) $reglas['arte']['regex'], 'la regla del arte') : null;
        $arte = ($catalogo || $regex) ? ['catalogo' => $catalogo, 'regex' => $regex] : null;

        return [
            'version' => 2,
            'identificador' => $identificador,
            'tipos_campania' => $tipos,
            'funnel' => $etapas,
            'campos' => $campos,
            'dimensiones' => $dimensiones,
            'arte' => $arte,
            'usar_objetivo_plataforma' => (bool) ($reglas['usar_objetivo_plataforma'] ?? true),
        ];
    }

    /**
     * Misma forma que ClasificadorNombres::parsearNombre(), más lo propio de
     * la cuenta. Un nombre sin el identificador de la cuenta NO es de ella:
     * sale sin plataforma, así el pipeline lo excluye en vez de mezclarlo
     * (cuentas publicitarias compartidas).
     *
     * @return array{plataforma: ?string, funnel: ?string, funnelPropio: ?string, fuenteFunnel: ?string, tipoCampania: ?string, tipoCampaniaNombre: ?string, tipoCuenta: null, patron: string, arte: ?string, videoFilename: ?string, formato: ?string, dimensiones: array<string, string>, esDeLaCuenta: bool, reglasAplicadas: array<string, string>}
     */
    public static function parsear(array $reglas, ?string $campaignRaw, ?string $adRaw, ?string $conjuntoRaw = null, ?string $objetivo = null, ?string $postId = null): array
    {
        // Orden de búsqueda: campaña, conjunto, anuncio.
        $textos = array_values(array_filter([$campaignRaw, $conjuntoRaw, $adRaw], fn ($t) => $t !== null && $t !== ''));
        $aplicadas = [];

        $esDeLaCuenta = ! $reglas['identificador'] || self::primera([$reglas['identificador']], $textos) !== null;

        $tipo = self::primera($reglas['tipos_campania'], $textos);
        $etapa = self::primera($reglas['funnel'], $textos);
        $fuenteFunnel = $etapa ? 'nombre' : null;
        $funnel = $etapa['equivalente'] ?? null;
        $funnelPropio = $etapa['nombre'] ?? null;

        if (! $etapa && $reglas['usar_objetivo_plataforma'] && $objetivo && isset(self::OBJETIVO_A_FUNNEL[mb_strtoupper($objetivo)])) {
            $funnel = self::OBJETIVO_A_FUNNEL[mb_strtoupper($objetivo)];
            // El nombre propio de la etapa que equivale, si la cuenta la tiene.
            $funnelPropio = collect($reglas['funnel'])->firstWhere('equivalente', $funnel)['nombre'] ?? null;
            $fuenteFunnel = 'objetivo';
            $aplicadas['funnel'] = "Objetivo de la campaña: {$objetivo}";
        }

        $leer = function (array $lista, string $campo) use ($textos, &$aplicadas) {
            $regla = self::primera($lista, $textos);
            if ($regla) {
                $aplicadas[$campo] = self::describir($regla);
            }

            return $regla['valor'] ?? null;
        };

        $dimensiones = [];
        foreach ($reglas['dimensiones'] as $d) {
            $valor = $leer($d['reglas'], $d['clave']);
            if ($valor !== null) {
                $dimensiones[$d['clave']] = $valor;
            }
        }

        [$arte, $reglaArte] = self::leerArte($reglas['arte'] ?? null, $adRaw, $postId);
        if ($reglaArte) {
            $aplicadas['arte'] = $reglaArte;
        }

        if ($tipo) {
            $aplicadas['tipo_campania'] = self::describir($tipo);
        }
        if ($etapa) {
            $aplicadas['funnel'] = self::describir($etapa);
        }

        return [
            'plataforma' => $esDeLaCuenta ? $leer($reglas['campos']['plataforma'], 'plataforma') : null,
            'funnel' => $funnel,
            'funnelPropio' => $funnelPropio,
            'fuenteFunnel' => $fuenteFunnel,
            'tipoCampania' => $tipo['eje'] ?? null,
            'tipoCampaniaNombre' => $tipo['nombre'] ?? null,
            'tipoCuenta' => null,
            'patron' => 'NOMENCLATURA_CUENTA',
            'arte' => $arte,
            'videoFilename' => ClasificadorNombres::extraerVideoFilename($adRaw),
            'formato' => $leer($reglas['campos']['formato'], 'formato'),
            'dimensiones' => $dimensiones,
            'esDeLaCuenta' => $esDeLaCuenta,
            'reglasAplicadas' => $aplicadas,
        ];
    }

    /**
     * Arte (el creativo que agrupa): 1) catálogo por post de TikTok, 2)
     * catálogo por patrón sobre el nombre del anuncio -- en un Spark Ad es el
     * texto del post --, 3) la regex de respaldo. El nombre del catálogo se
     * usa tal cual ("Outfit Match"); el de la regex, en mayúsculas como TaDa.
     *
     * @return array{0: ?string, 1: ?string} [arte, regla que lo dio]
     */
    private static function leerArte(?array $arte, ?string $adRaw, ?string $postId): array
    {
        if (! $arte) {
            return [null, null];
        }
        $catalogo = $arte['catalogo'] ?? [];
        if ($postId !== null && $postId !== '') {
            foreach ($catalogo as $r) {
                if (($r['post_id'] ?? null) === $postId) {
                    return [$r['valor'], "post {$postId}"];
                }
            }
        }
        if ($adRaw !== null && $adRaw !== '') {
            foreach ($catalogo as $r) {
                if (! isset($r['post_id']) && preg_match(self::regexDe($r), $adRaw)) {
                    return [$r['valor'], 'catálogo: '.self::describir($r)];
                }
            }
            if (($arte['regex'] ?? null) && preg_match(self::delimitar($arte['regex']), $adRaw, $m) && filled($m[1] ?? null)) {
                return [mb_strtoupper($m[1]), 'regex del arte'];
            }
        }

        return [null, null];
    }

    /** Equivalente -> nombre propio (la primera etapa de cada uno), para mostrar. */
    public static function nombresDeEtapas(?array $reglas): array
    {
        $nombres = [];
        foreach ($reglas['funnel'] ?? [] as $e) {
            $nombres[$e['equivalente']] ??= $e['nombre'];
        }

        return $nombres;
    }

    /**
     * Primera regla que coincide, recorriendo los textos en orden (campaña,
     * conjunto, anuncio): un dato en la campaña gana a uno en el anuncio.
     */
    private static function primera(array $reglas, array $textos): ?array
    {
        foreach ($textos as $texto) {
            foreach ($reglas as $regla) {
                if (preg_match(self::regexDe($regla), $texto)) {
                    return $regla;
                }
            }
        }

        return null;
    }

    /**
     * Siglas (palabra completa) y/o patrón. Devuelve null si no hay ninguno.
     *
     * @return array{siglas: list<string>, patron: ?string}|null
     */
    private static function condicion(mixed $datos, string $que): ?array
    {
        if (! is_array($datos)) {
            return null;
        }
        $siglas = collect(is_array($datos['siglas'] ?? null) ? $datos['siglas'] : explode(',', (string) ($datos['siglas'] ?? '')))
            ->map(fn ($s) => mb_strtolower(trim((string) $s)))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $patron = filled($datos['patron'] ?? null) ? self::validarRegex((string) $datos['patron'], $que) : null;

        return ($siglas || $patron) ? ['siglas' => $siglas, 'patron' => $patron] : null;
    }

    /** @return list<array{siglas: list<string>, patron: ?string, valor: string}> */
    private static function reglasConValor(array $lista, string $campo, ?array $permitidos, callable $formato): array
    {
        // Tolerancia: forma v1 ({posicion, valores: {token: valor}}) dentro de
        // una v2 -- la IA a veces la mezcla.
        if (isset($lista['valores']) && is_array($lista['valores'])) {
            $lista = collect($lista['valores'])->map(fn ($v, $token) => ['siglas' => [(string) $token], 'valor' => $v])->values()->all();
        }
        $salida = [];
        foreach ($lista as $r) {
            if (! is_array($r)) {
                continue;
            }
            $valor = $formato(trim((string) ($r['valor'] ?? '')));
            $condicion = self::condicion($r, $campo);
            if ($valor === '' || ! $condicion) {
                continue;
            }
            if ($permitidos !== null && ! in_array($valor, $permitidos, true)) {
                throw new InvalidArgumentException("\"{$valor}\" no es un valor válido para {$campo}. Usa: ".implode(', ', $permitidos).'.');
            }
            $salida[] = [...$condicion, 'valor' => $valor];
        }

        return $salida;
    }

    /** Solo los elementos que son arreglos (lo demás viene mal formado). */
    private static function lista(mixed $valor): array
    {
        return is_array($valor) ? array_values(array_filter($valor, 'is_array')) : [];
    }

    /** Siglas como palabra completa: "do" no coincide dentro de "android". */
    private static function regexDe(array $regla): string
    {
        $partes = [];
        if ($regla['siglas']) {
            $partes[] = '(?<![\p{L}\p{N}])(?:'.implode('|', array_map(fn ($s) => preg_quote($s, '~'), $regla['siglas'])).')(?![\p{L}\p{N}])';
        }
        if ($regla['patron']) {
            $partes[] = '(?:'.str_replace('~', '\~', $regla['patron']).')';
        }

        return '~'.implode('|', $partes).'~iu';
    }

    private static function describir(array $regla): string
    {
        return collect([
            $regla['siglas'] ? 'siglas '.implode(', ', $regla['siglas']) : null,
            $regla['patron'] ? "patrón {$regla['patron']}" : null,
        ])->filter()->implode(' o ');
    }

    private static function validarRegex(string $regex, string $que): string
    {
        if (@preg_match(self::delimitar($regex), '') === false) {
            throw new InvalidArgumentException("El patrón de {$que} no es una expresión regular válida: {$regex}");
        }

        return $regex;
    }

    private static function delimitar(string $regex): string
    {
        return '~'.str_replace('~', '\~', $regex).'~iu';
    }

    /**
     * Versión 1 (token en posición, 2026-11-04 temprano) -> versión 2: cada
     * token pasa a siglas; la posición se descarta (con siglas de palabra
     * completa ya no hace falta).
     */
    private static function desdeVersion1(array $v1): array
    {
        $aReglas = fn (array $valores) => collect($valores)->map(fn ($valor, $token) => ['siglas' => [(string) $token], 'valor' => $valor])->values()->all();

        return [
            'version' => 2,
            'identificador' => filled($v1['identificador']['segmento'] ?? null) ? ['siglas' => [$v1['identificador']['segmento']]] : null,
            'tipos_campania' => [],
            'funnel' => collect($v1['campos']['funnel']['valores'] ?? [])
                ->map(fn ($equivalente, $token) => ['nombre' => Str::ucfirst((string) $token), 'equivalente' => $equivalente, 'siglas' => [(string) $token]])
                ->values()->all(),
            'campos' => [
                'plataforma' => $aReglas($v1['campos']['plataforma']['valores'] ?? []),
                'formato' => $aReglas($v1['campos']['formato']['valores'] ?? []),
            ],
            'dimensiones' => collect($v1['dimensiones'] ?? [])
                ->map(fn ($d) => ['clave' => $d['clave'] ?? null, 'nombre' => $d['nombre'] ?? '', 'reglas' => $aReglas($d['valores'] ?? [])])
                ->all(),
            'arte' => $v1['arte'] ?? null,
        ];
    }
}
