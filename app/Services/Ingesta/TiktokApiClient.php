<?php

namespace App\Services\Ingesta;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Puerto de tiktokApiGet (pipeline.js, proyecto Node, referencia). Auth de
 * TikTok es DISTINTO a Meta: el token va en el header "Access-Token", nunca
 * como query param, y la respuesta siempre viene envuelta en
 * {code, message, request_id, data} -- code 0 es éxito, cualquier otro
 * código es error (el mensaje real de TikTok se preserva tal cual).
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
        ])->get(self::BASE_URL.$pathAndSlash, $query);

        $data = $respuesta->json() ?? [];

        if (! $respuesta->successful() || ($data['code'] ?? null) !== 0) {
            $mensaje = $data['message'] ?? json_encode($data);
            throw new RuntimeException("TikTok API ({$pathAndSlash}) respondió code {$data['code']}: {$mensaje}");
        }

        return $data['data'];
    }
}
