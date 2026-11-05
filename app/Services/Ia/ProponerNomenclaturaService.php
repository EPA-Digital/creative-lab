<?php

namespace App\Services\Ia;

use App\Models\Cuenta;
use App\Services\Ingesta\ReglasNomenclatura;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Le pide a Claude que proponga las reglas de clasificación de una cuenta a
 * partir del cuestionario que llena el equipo al dar de alta la cuenta:
 * sus tipos de campaña (con siglas y ejemplos), sus etapas de funnel (con
 * equivalente y ejemplos), nombres reales de campañas, conjuntos y
 * anuncios, y la explicación de su nomenclatura.
 *
 * Lo que el equipo ya dijo manda: los nombres, ejes, equivalentes y siglas
 * del cuestionario se respetan siempre (se vuelven a poner encima de lo que
 * proponga la IA). La IA solo completa lo que falta -- patrones cuando no
 * hay siglas, plataforma, formato, dimensiones, arte, identificador. Todo
 * pasa por ReglasNomenclatura::normalizar() y se prueba contra los mismos
 * ejemplos antes de guardar. Nunca clasifica anuncio por anuncio.
 */
class ProponerNomenclaturaService
{
    public function __construct(private readonly AnthropicApiClient $cliente) {}

    /**
     * @param  array<string, mixed>  $cuestionario  ver CuentasController::validarCuestionario()
     * @return array<string, mixed> reglas v2 ya normalizadas
     */
    public function proponer(string $nombreCuenta, array $cuestionario): array
    {
        $respuesta = $this->cliente->crearMensaje([
            ['type' => 'text', 'text' => $this->prompt($nombreCuenta, $cuestionario)],
        ], maxTokens: 3000);

        try {
            $reglas = json_decode(self::soloJson($respuesta), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('La IA no devolvió reglas legibles. Intenta de nuevo o agrega más ejemplos.');
        }

        try {
            return ReglasNomenclatura::normalizar(self::respetarCuestionario(is_array($reglas) ? $reglas : [], $cuestionario));
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('La IA propuso una regla inválida: '.$e->getMessage().' Intenta de nuevo.');
        }
    }

    /**
     * Reglas armadas SOLO con lo que el equipo escribió (siglas de tipos y
     * etapas), sin IA -- para probar el cuestionario antes de pedir nada.
     *
     * @return array<string, mixed>
     */
    public static function desdeCuestionario(array $cuestionario): array
    {
        return ReglasNomenclatura::normalizar(self::respetarCuestionario(['version' => 2], $cuestionario));
    }

    /**
     * Tipos y etapas del cuestionario por encima de la propuesta: mismo
     * nombre/eje/equivalente; las siglas del equipo se agregan a lo que haya
     * propuesto la IA para esa entrada. Una entrada del cuestionario que la
     * IA no devolvió se agrega igual.
     */
    private static function respetarCuestionario(array $reglas, array $cuestionario): array
    {
        $reglas['version'] = 2;
        foreach (['tipos' => ['tipos_campania', 'eje'], 'etapas' => ['funnel', 'equivalente']] as $origen => [$destino, $atributo]) {
            $propuestas = collect($reglas[$destino] ?? [])->keyBy(fn ($r) => mb_strtolower(trim((string) ($r['nombre'] ?? ''))));
            $final = [];
            foreach ($cuestionario[$origen] ?? [] as $item) {
                $clave = mb_strtolower(trim((string) ($item['nombre'] ?? '')));
                if ($clave === '') {
                    continue;
                }
                $propuesta = $propuestas->pull($clave) ?? [];
                $siglas = collect(explode(',', (string) ($item['siglas'] ?? '')))->map(fn ($s) => trim($s))->filter();
                $final[] = [
                    'nombre' => trim($item['nombre']),
                    $atributo => $item[$atributo],
                    'siglas' => $siglas->merge(is_array($propuesta['siglas'] ?? null) ? $propuesta['siglas'] : [])->unique()->values()->all(),
                    'patron' => $propuesta['patron'] ?? null,
                ];
            }
            // Si el equipo no llenó esta parte, se queda lo que propuso la IA.
            $reglas[$destino] = $final ?: ($reglas[$destino] ?? []);
        }

        return $reglas;
    }

    private function prompt(string $nombreCuenta, array $c): string
    {
        $lista = fn (array $nombres) => $nombres ? implode("\n", array_map(fn ($n) => "  - {$n}", $nombres)) : '  (ninguno)';
        $plataformas = implode(', ', array_keys(Cuenta::MEDIOS));

        $tipos = collect($c['tipos'] ?? [])->map(fn ($t) => "- {$t['nombre']} (eje: {$t['eje']}; siglas: ".(($t['siglas'] ?? '') ?: 'no las dijo').")\n  Ejemplos:\n".$lista($t['ejemplos'] ?? []))->implode("\n");
        $etapas = collect($c['etapas'] ?? [])->map(fn ($e) => "- {$e['nombre']} (equivale a {$e['equivalente']}; siglas: ".(($e['siglas'] ?? '') ?: 'no las dijo').")\n  Ejemplos de campañas:\n".$lista($e['ejemplos'] ?? []))->implode("\n");
        $notas = filled($c['notas'] ?? null) ? "\nExplicación del equipo sobre su nomenclatura:\n{$c['notas']}\n" : '';

        return <<<PROMPT
        Eres analista de medios en una agencia. El equipo de la cuenta "{$nombreCuenta}" nos explicó cómo nombra sus campañas, conjuntos (ad sets / ad groups) y anuncios. Las nomenclaturas cambian entre cuentas y a veces dentro de la misma cuenta, así que las reglas son expresiones regulares tipo REGEXMATCH.

        Sus tipos de campaña:
        {$tipos}

        Sus etapas de funnel (AWA = awareness, CONS = consideración, CNV = conversión, LOY = loyalty):
        {$etapas}

        Nombres de campañas:
        {$lista($c['campanias'] ?? [])}

        Nombres de conjuntos (ad sets / ad groups):
        {$lista($c['conjuntos'] ?? [])}

        Nombres de anuncios:
        {$lista($c['anuncios'] ?? [])}
        {$notas}
        Devuelve SOLO un objeto JSON (sin texto antes ni después, sin ```), con esta forma:
        {
          "version": 2,
          "identificador": {"siglas": ["<código del cliente que aparece en todos sus nombres>"], "patron": null},
          "tipos_campania": [{"nombre": "<igual que arriba>", "eje": "<igual que arriba>", "siglas": [], "patron": "<regex o null>"}],
          "funnel": [{"nombre": "<igual que arriba>", "equivalente": "<igual que arriba>", "siglas": [], "patron": "<regex o null>"}],
          "campos": {
            "plataforma": [{"siglas": ["<sigla>"], "patron": null, "valor": "<uno de: {$plataformas}>"}],
            "formato": [{"siglas": ["<sigla>"], "patron": null, "valor": "<VIDEO | IMAGEN | CATALOGO>"}]
          },
          "dimensiones": [{"nombre": "<ej. Producto>", "reglas": [{"siglas": ["<sigla>"], "patron": null, "valor": "<etiqueta legible>"}]}],
          "arte": {"regex": "<regex sin delimitadores cuyo grupo 1 capture el arte del nombre del ANUNCIO>"},
          "usar_objetivo_plataforma": true
        }

        Reglas:
        - Respeta los tipos y etapas que dio el equipo: mismos nombres, ejes y equivalentes. No inventes tipos ni etapas nuevas.
        - "siglas" son palabras completas entre separadores (no coinciden dentro de otra palabra). Prefiere siglas; usa "patron" (regex, sin flags, no distingue mayúsculas) solo cuando las siglas no alcancen, p. ej. variantes como "ios-14".
        - Los ejemplos etiquetados son la verdad: cada ejemplo de una etapa debe clasificarse en esa etapa con tus reglas, y ninguno de otra etapa debe coincidir.
        - Las reglas se buscan en la campaña, luego en el conjunto y luego en el anuncio; la primera que coincide gana.
        - dimensiones: otras partes del nombre por las que el equipo agrupa o filtra (producto, sistema operativo, audiencia, oferta, categoría…). Máximo 6. No repitas plataforma, funnel, formato ni tipo de campaña.
        - arte es la parte del nombre del anuncio que identifica a la pieza creativa (creador, fecha de la pieza, concepto), para agrupar el mismo creativo aunque cambie la campaña; excluye la versión final (_v1, _v2).
        - Omite lo que los ejemplos no muestren. No inventes siglas que no aparecen.
        PROMPT;
    }

    /** Quita ``` o texto alrededor si el modelo los agrega igual. */
    private static function soloJson(string $texto): string
    {
        $inicio = strpos($texto, '{');
        $fin = strrpos($texto, '}');

        return $inicio === false || $fin === false ? $texto : substr($texto, $inicio, $fin - $inicio + 1);
    }
}
