<?php

namespace App\Services\Ia;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Wrapper HTTP mínimo sobre la Messages API de Anthropic (2026-08-26, ver
 * plan de evaluación IA de creativos) -- mismo patrón que MetaApiClient
 * (Ingesta): fromConfig() estático, Http::retry() con el mismo backoff
 * (intento inicial + 3 reintentos, 1s/3s/8s) solo en fallas transitorias
 * (timeout/conexión o status 429/5xx), RuntimeException con el mensaje
 * real del error si la API responde con algo distinto de éxito.
 */
class AnthropicApiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $apiVersion = '2023-06-01',
    ) {}

    public static function fromConfig(): self
    {
        $key = config('services.anthropic.api_key');
        if (! $key) {
            throw new RuntimeException('Falta ANTHROPIC_API_KEY en el entorno.');
        }

        return new self($key, config('services.anthropic.model', 'claude-sonnet-5'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $contentBlocks
     */
    public function crearMensaje(array $contentBlocks, int $maxTokens = 1024): string
    {
        $respuesta = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->apiVersion,
        ])->retry(4, fn (int $intento) => [1000, 3000, 8000][$intento - 1] ?? 8000, function ($exception) {
            return $exception instanceof ConnectionException
                || ($exception instanceof RequestException && in_array($exception->response->status(), [429, 500, 502, 503, 504], true));
        }, throw: false)->post('https://api.anthropic.com/v1/messages', [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $contentBlocks]],
        ]);

        $data = $respuesta->json() ?? [];
        if (! $respuesta->successful() || isset($data['error'])) {
            $mensaje = $data['error']['message'] ?? json_encode($data);
            throw new RuntimeException("Anthropic API respondió {$respuesta->status()}: {$mensaje}");
        }

        return collect($data['content'] ?? [])->firstWhere('type', 'text')['text'] ?? '';
    }
}
