<?php

namespace App\Services\Ingesta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Puerto de metaGraphGet (pipeline.js, proyecto Node, referencia). Wrapper
 * HTTP mínimo sobre la Graph API de Meta -- construye la URL con
 * access_token y traduce el error {error:{...}} de Graph API a una
 * excepción con el mensaje real, nunca un "falló" genérico.
 *
 * Reintenta (intento inicial + 3 reintentos, backoff 1s/3s/8s) solo fallas transitorias --
 * timeout/conexión o status 429/5xx. NOTA: Meta a veces devuelve rate limit
 * con error.code (4/17/32/613) en el body con HTTP 200 -- ese caso NO dispara
 * retry acá (successful() ve un 200 y no entra al chequeo de retry de Http::
 * retry()). Antes de intentar cubrir ese caso hace falta confirmar en
 * storage/logs/laravel.log que realmente ocurre así en este proyecto, no
 * asumir el código sin haberlo visto en un log real.
 */
class MetaApiClient
{
    public function __construct(
        private readonly string $accessToken,
        private readonly string $graphVersion = 'v21.0',
    ) {}

    public static function fromConfig(): self
    {
        $token = config('services.meta.access_token');
        if (! $token) {
            throw new RuntimeException('Falta META_ACCESS_TOKEN en el entorno.');
        }

        return new self($token, config('services.meta.graph_version', 'v21.0'));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function get(string $pathAndId, array $params): array
    {
        $params['access_token'] = $this->accessToken;

        $respuesta = Http::retry(4, fn (int $intento) => [1000, 3000, 8000][$intento - 1] ?? 8000, function ($exception) {
            return $exception instanceof ConnectionException
                || ($exception instanceof RequestException
                    && in_array($exception->response->status(), [429, 500, 502, 503, 504], true)
                    // "Please reduce the amount of data you're asking for"
                    // es determinístico -- la misma tanda falla igual en
                    // cada reintento (4 intentos + esperas de 1/3/8s por
                    // tanda, minutos perdidos, 2026-10-06). Se corta al
                    // primer intento y el caller parte la tanda.
                    && ! self::esErrorDeDemasiadosDatos($exception->response->body()));
        }, throw: false)->get(self::url($this->graphVersion, $pathAndId), $params);

        return self::datosOExcepcion($respuesta, $pathAndId);
    }

    /**
     * Varias llamadas GET en paralelo (Http::pool) -- para las tandas de
     * status/imagen/copy de EnriquecedorCostosMeta, que antes iban una por
     * una. Sin reintentos acá: cada resultado es el `data` de la respuesta
     * o la excepción, y el caller decide (reintento secuencial con split).
     *
     * @param  array<string|int, array{0: string, 1: array<string, mixed>}>  $peticiones  clave => [path, params]
     * @return array<string|int, array<string, mixed>|Throwable>
     */
    public function getVarios(array $peticiones): array
    {
        $respuestas = Http::pool(function (Pool $pool) use ($peticiones) {
            foreach ($peticiones as $clave => [$path, $params]) {
                $pool->as((string) $clave)->timeout(120)->get(self::url($this->graphVersion, $path), [...$params, 'access_token' => $this->accessToken]);
            }
        });

        $resultados = [];
        foreach ($peticiones as $clave => [$path]) {
            $respuesta = $respuestas[(string) $clave] ?? null;
            try {
                $resultados[$clave] = $respuesta instanceof Response
                    ? self::datosOExcepcion($respuesta, $path)
                    : throw new RuntimeException("Meta Graph API ({$path}) sin respuesta: ".($respuesta instanceof Throwable ? $respuesta->getMessage() : 'desconocido'));
            } catch (Throwable $e) {
                $resultados[$clave] = $e;
            }
        }

        return $resultados;
    }

    public static function esErrorDeDemasiadosDatos(string $cuerpo): bool
    {
        return str_contains($cuerpo, 'reduce the amount of data');
    }

    private static function url(string $version, string $pathAndId): string
    {
        return "https://graph.facebook.com/{$version}/{$pathAndId}";
    }

    /**
     * @return array<string, mixed>
     */
    private static function datosOExcepcion(Response $respuesta, string $pathAndId): array
    {
        $data = $respuesta->json() ?? [];

        if (! $respuesta->successful() || isset($data['error'])) {
            $mensaje = $data['error']['message'] ?? json_encode($data);
            throw new RuntimeException("Meta Graph API ({$pathAndId}) respondió {$respuesta->status()}: {$mensaje}");
        }

        return $data;
    }
}
