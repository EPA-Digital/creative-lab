<?php

namespace App\Services\Ingesta;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Puerto de metaGraphGet (pipeline.js, proyecto Node, referencia). Wrapper
 * HTTP mínimo sobre la Graph API de Meta -- construye la URL con
 * access_token y traduce el error {error:{...}} de Graph API a una
 * excepción con el mensaje real, nunca un "falló" genérico.
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

        $respuesta = Http::get("https://graph.facebook.com/{$this->graphVersion}/{$pathAndId}", $params);
        $data = $respuesta->json() ?? [];

        if (! $respuesta->successful() || isset($data['error'])) {
            $mensaje = $data['error']['message'] ?? json_encode($data);
            throw new RuntimeException("Meta Graph API ({$pathAndId}) respondió {$respuesta->status()}: {$mensaje}");
        }

        return $data;
    }
}
