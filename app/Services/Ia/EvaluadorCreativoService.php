<?php

namespace App\Services\Ia;

use App\Models\Creativo;
use App\Models\CreativoEvaluacion;
use App\Models\Resultado;
use Illuminate\Support\Facades\Log;
use JsonException;

// Orquesta las dos evaluaciones IA del modal de detalle de creativo
// (2026-08-26, ver plan) -- "Evaluar por métricas" (narrativa a partir de
// los números) y "Evaluar por arte" (lectura visual de la imagen
// cacheada). Cachea en creativo_evaluaciones por (creativo_id, mes, modo)
// -- un click repetido nunca vuelve a llamar a Anthropic. Nunca confía en
// números que mande el cliente: los promedios de grupo se recalculan acá
// mismo criterio de "país+plataforma+funnel+mes, excluye self" que
// promediosParaCreativo() en resources/js/Pages/AnalisisCreativo.vue --
// si esa definición de "grupo" cambia algún día, hay que actualizar las
// dos.
class EvaluadorCreativoService
{
    private const FUNNEL_LABEL = [
        'AWA' => 'Awareness',
        'CON' => 'Consideración',
        'CONS' => 'Consideración',
        'CNV' => 'Conversión',
        'LOY' => 'Loyalty',
    ];

    public function __construct(private readonly AnthropicApiClient $cliente) {}

    public function evaluarPorMetricas(Creativo $creativo, string $mes): CreativoEvaluacion
    {
        $cache = CreativoEvaluacion::where([
            'creativo_id' => $creativo->id,
            'mes' => $mes,
            'modo' => 'metricas',
        ])->first();
        if ($cache) {
            return $cache;
        }

        $resultado = $creativo->resultados()->where('mes', $mes)->firstOrFail();
        $promedios = $this->promediosDeGrupo($creativo, $mes);
        $texto = $this->cliente->crearMensaje([
            ['type' => 'text', 'text' => $this->promptMetricas($creativo, $resultado, $promedios)],
        ]);

        return CreativoEvaluacion::create([
            'creativo_id' => $creativo->id,
            'mes' => $mes,
            'modo' => 'metricas',
            'resultado' => $texto,
            'modelo' => config('services.anthropic.model'),
        ]);
    }

    public function evaluarPorArte(Creativo $creativo, string $mes): CreativoEvaluacion
    {
        $cache = CreativoEvaluacion::where([
            'creativo_id' => $creativo->id,
            'mes' => $mes,
            'modo' => 'arte',
        ])->first();
        if ($cache) {
            return $cache;
        }

        abort_if(! $creativo->imagen_url, 422, 'Este creativo no tiene imagen disponible para evaluar por arte.');
        $rutaAbsoluta = public_path(ltrim($creativo->imagen_url, '/'));
        abort_unless(is_file($rutaAbsoluta), 422, 'El archivo de imagen cacheado ya no existe en disco.');

        $mediaType = mime_content_type($rutaAbsoluta) ?: 'image/jpeg';
        $b64 = base64_encode(file_get_contents($rutaAbsoluta));

        $respuesta = $this->cliente->crearMensaje([
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $b64]],
            ['type' => 'text', 'text' => $this->promptArte($creativo)],
        ]);
        [$texto, $score, $desglose] = $this->parsearRespuestaArte($respuesta);

        return CreativoEvaluacion::create([
            'creativo_id' => $creativo->id,
            'mes' => $mes,
            'modo' => 'arte',
            'resultado' => $texto,
            'score' => $score,
            'desglose' => $desglose,
            'modelo' => config('services.anthropic.model'),
        ]);
    }

    /**
     * Parsea el JSON estructurado que pide promptArte(). Degradación segura
     * (2026-08-26, pedido explícito: "nunca un 500 duro por un formato
     * inesperado") -- si Claude no devuelve JSON válido o le faltan
     * campos, se guarda igual el texto crudo como `resultado` con
     * score/desglose en null, y se loggea para poder ajustar el prompt.
     *
     * @return array{0: string, 1: int|null, 2: array<string,int>|null}
     */
    private function parsearRespuestaArte(string $respuesta): array
    {
        try {
            $datos = json_decode($respuesta, associative: true, flags: JSON_THROW_ON_ERROR);
            $categorias = $datos['categorias'] ?? null;
            $resultado = $datos['resultado'] ?? null;
            if (! is_array($categorias) || ! is_string($resultado)) {
                throw new JsonException('Faltan campos "categorias"/"resultado" en la respuesta de Claude.');
            }
            $categorias = array_map(fn ($v) => max(0, min(25, (int) $v)), $categorias);
            $score = array_sum($categorias); // 4 categorías x 25 pts = ya está en base 100

            return [$resultado, $score, $categorias];
        } catch (JsonException $e) {
            Log::warning('EvaluadorCreativoService: respuesta de Claude para "arte" no es el JSON esperado.', [
                'error' => $e->getMessage(),
                'respuesta' => $respuesta,
            ]);

            return [$respuesta, null, null];
        }
    }

    /**
     * @return array<string, float|null>
     */
    private function promediosDeGrupo(Creativo $creativo, string $mes): array
    {
        $grupo = fn () => Resultado::where('mes', $mes)
            ->whereHas('creativo', fn ($q) => $q->where('plataforma', $creativo->plataforma)
                ->where('funnel', $creativo->funnel)
                ->where('id', '!=', $creativo->id));

        return [
            'cpm' => $grupo()->avg('cpm'),
            'ctr' => $grupo()->avg('ctr'),
            'cpi' => $grupo()->avg('cpi'),
            'cpo' => $grupo()->avg('cpo'),
            'cac' => $grupo()->avg('cac'),
        ];
    }

    private function promptMetricas(Creativo $creativo, Resultado $resultado, array $promedios): string
    {
        $etapa = self::FUNNEL_LABEL[$creativo->funnel] ?? 'sin clasificar';
        $nombre = $creativo->nombre_comun ?: $creativo->nombre_completo;

        $lineas = [
            "Costo: \${$resultado->cost}",
            "Impresiones: {$resultado->impressions}, Clicks: {$resultado->clicks}, CTR: {$resultado->ctr}% (promedio del grupo: {$this->fmt($promedios['ctr'])}%)",
            "CPM: \${$resultado->cpm} (promedio del grupo: \${$this->fmt($promedios['cpm'])})",
            "Instalaciones: {$resultado->installs}, CPI: \${$this->fmt($resultado->cpi)} (promedio del grupo: \${$this->fmt($promedios['cpi'])})",
            "Órdenes: {$resultado->orders}, CPO: \${$this->fmt($resultado->cpo)} (promedio del grupo: \${$this->fmt($promedios['cpo'])})",
            "Nuevos clientes: {$resultado->nc}, CAC: \${$this->fmt($resultado->cac)} (promedio del grupo: \${$this->fmt($promedios['cac'])})",
        ];

        return "Eres un analista de performance de marketing digital. Evalúa este anuncio de \"{$nombre}\" ".
            "(etapa de funnel: {$etapa}) comparándolo contra el promedio de su grupo (mismo país, plataforma, ".
            "funnel y mes). Datos:\n".implode("\n", $lineas).
            "\n\nDa un juicio breve (3-4 oraciones, en español, tono consultivo y directo) sobre qué tan bien ".
            'rinde este anuncio en su etapa y qué palanca de negocio probarías primero. No inventes datos que no te di.';
    }

    private function promptArte(Creativo $creativo): string
    {
        $etapa = self::FUNNEL_LABEL[$creativo->funnel] ?? 'sin clasificar';
        $nombre = $creativo->nombre_comun ?: $creativo->nombre_completo;

        return "Eres un director de arte evaluando creativos publicitarios. Mira la imagen adjunta del anuncio ".
            "\"{$nombre}\" (etapa de funnel: {$etapa}) y califica sus elementos visuales en 4 categorías, cada ".
            "una de 0 a 25 puntos: \"color\" (paleta, contraste, legibilidad), \"composicion\" (jerarquía visual, ".
            "encuadre), \"texto\" (claridad y legibilidad del texto en pantalla, si lo hay) y \"gancho\" (qué tan ".
            "fuerte es el gancho visual en los primeros segundos). Responde ÚNICAMENTE con un objeto JSON válido, ".
            "sin texto antes ni después, con esta forma exacta:\n".
            '{"categorias": {"color": <0-25>, "composicion": <0-25>, "texto": <0-25>, "gancho": <0-25>}, '.
            '"resultado": "<3-4 oraciones en español, tono consultivo, solo sobre lo que ves en la imagen, sin '.
            'hablar de métricas de performance>"}';
    }

    private function fmt($valor): string
    {
        return $valor === null ? 'sin dato' : number_format((float) $valor, 2);
    }
}
