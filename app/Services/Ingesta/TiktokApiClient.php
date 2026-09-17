<?php

namespace App\Services\Ingesta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Puerto de tiktokApiGet (pipeline.js, proyecto Node, referencia). Auth de
 * TikTok es DISTINTO a Meta: el token va en el header "Access-Token", nunca
 * como query param, y la respuesta siempre viene envuelta en
 * {code, message, request_id, data} -- code 0 es éxito, cualquier otro
 * código es error (el mensaje real de TikTok se preserva tal cual).
 *
 * Reintenta (intento inicial + 3 reintentos, backoff 1s/3s/8s) solo fallas transitorias --
 * timeout/conexión o status 429/5xx. TikTok envuelve sus propios errores de
 * negocio (incluido rate limit) en el body con HTTP 200 -- ese caso NO
 * dispara retry acá porque no hay evidencia confirmada en este proyecto de
 * cuál es el código exacto de rate limit de TikTok; agregarlo requiere
 * verificarlo primero contra logs reales o la documentación de TikTok, no
 * adivinarlo.
 */
class TiktokApiClient
{
    private const BASE_URL = 'https://business-api.tiktok.com/open_api/v1.3';

    public function __construct(private readonly string $accessToken) {}

    public static function fromConfig(): self
    {
        $token = config('services.tiktok.access_token');
        if (! $token) {
            throw new RuntimeException('Falta TIKTOK_ACCESS_TOKEN en el entorno.');
        }

        return new self($token);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function get(string $pathAndSlash, array $params): array
    {
        $query = [];
        foreach ($params as $k => $v) {
            $query[$k] = is_string($v) ? $v : json_encode($v);
        }

        $respuesta = Http::withHeaders([
            'Access-Token' => $this->accessToken,
            'Content-Type' => 'application/json',
        ])->retry(4, fn (int $intento) => [1000, 3000, 8000][$intento - 1] ?? 8000, function ($exception) {
            return $exception instanceof ConnectionException
                || ($exception instanceof RequestException && in_array($exception->response->status(), [429, 500, 502, 503, 504], true));
        }, throw: false)->get(self::BASE_URL.$pathAndSlash, $query);

        $data = $respuesta->json() ?? [];

        if (! $respuesta->successful() || ($data['code'] ?? null) !== 0) {
            $mensaje = $data['message'] ?? json_encode($data);
            throw new RuntimeException("TikTok API ({$pathAndSlash}) respondió code {$data['code']}: {$mensaje}");
        }

        return $data['data'];
    }
}
