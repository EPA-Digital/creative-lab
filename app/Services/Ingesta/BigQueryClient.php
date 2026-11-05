<?php

namespace App\Services\Ingesta;

use Closure;
use Google\Auth\ApplicationDefaultCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente mínimo de la API REST de BigQuery (2026-11-05) -- solo lectura:
 * listar tablas, leer su esquema y correr consultas SELECT parametrizadas.
 * Sin librería nueva: usa google/auth (ya instalado por cloud-storage) para
 * las credenciales por defecto -- en Cloud Run, la cuenta de servicio del
 * dashboard; en local, `gcloud auth application-default login`.
 *
 * Los datos viven en el proyecto de datos de EPA (bdd-epa-digital); las
 * consultas se cobran al proyecto del dashboard (services.bigquery.
 * proyecto_consultas), que necesita BigQuery Job User; los datasets, que la
 * cuenta de servicio tenga lector.
 */
class BigQueryClient
{
    private const BASE = 'https://bigquery.googleapis.com/bigquery/v2';

    /** Tope de bytes por consulta: si una regla sale mal no se escanea de más. */
    private const MAX_BYTES = 20 * 1024 ** 3;

    /** @param  Closure(): string  $token */
    public function __construct(private readonly Closure $token, private readonly string $proyectoConsultas) {}

    public static function fromConfig(): self
    {
        $proyecto = config('services.bigquery.proyecto_consultas');
        if (! $proyecto) {
            throw new RuntimeException('Falta BIGQUERY_PROYECTO_CONSULTAS (el proyecto que paga las consultas).');
        }

        return new self(function () {
            $credenciales = ApplicationDefaultCredentials::getCredentials(['https://www.googleapis.com/auth/cloud-platform']);
            $token = $credenciales->fetchAuthToken()['access_token'] ?? null;
            if (! $token) {
                throw new RuntimeException('No hay credenciales de Google para BigQuery en este servidor.');
            }

            return $token;
        }, $proyecto);
    }

    /** @return list<string> */
    public function tablas(string $proyecto, string $dataset): array
    {
        $tablas = [];
        $pagina = null;
        do {
            $r = $this->get("/projects/{$proyecto}/datasets/{$dataset}/tables", array_filter(['maxResults' => 1000, 'pageToken' => $pagina]));
            foreach ($r['tables'] ?? [] as $t) {
                $tablas[] = $t['tableReference']['tableId'];
            }
            $pagina = $r['nextPageToken'] ?? null;
        } while ($pagina);

        return $tablas;
    }

    /** @return list<string> nombres de columna (primer nivel) */
    public function columnas(string $proyecto, string $dataset, string $tabla): array
    {
        $r = $this->get("/projects/{$proyecto}/datasets/{$dataset}/tables/{$tabla}");

        return array_map(fn ($c) => $c['name'], $r['schema']['fields'] ?? []);
    }

    /**
     * SELECT con parámetros con nombre (@desde, @apps…) -- nunca se arma SQL
     * con valores pegados. Devuelve filas como arrays asociativos de strings.
     *
     * @param  array<string, string|list<string>>  $parametros
     * @return list<array<string, ?string>>
     */
    public function consultar(string $sql, array $parametros = []): array
    {
        $cuerpo = [
            'query' => $sql,
            'useLegacySql' => false,
            'parameterMode' => 'NAMED',
            'timeoutMs' => 60000,
            'maximumBytesBilled' => (string) self::MAX_BYTES,
            'queryParameters' => array_map(fn ($nombre, $valor) => is_array($valor)
                ? ['name' => $nombre, 'parameterType' => ['type' => 'ARRAY', 'arrayType' => ['type' => 'STRING']], 'parameterValue' => ['arrayValues' => array_map(fn ($v) => ['value' => (string) $v], $valor)]]
                : ['name' => $nombre, 'parameterType' => ['type' => 'STRING'], 'parameterValue' => ['value' => (string) $valor]],
                array_keys($parametros), $parametros),
        ];

        $r = $this->post("/projects/{$this->proyectoConsultas}/queries", $cuerpo);
        $jobId = $r['jobReference']['jobId'] ?? null;
        $ubicacion = $r['jobReference']['location'] ?? null;
        $campos = $r['schema']['fields'] ?? [];
        $filas = $r['rows'] ?? [];
        $pagina = $r['pageToken'] ?? null;

        // Consulta larga: se espera a que termine; después se pagina.
        for ($intento = 0; ! ($r['jobComplete'] ?? true) && $intento < 10; $intento++) {
            $r = $this->get("/projects/{$this->proyectoConsultas}/queries/{$jobId}", array_filter(['location' => $ubicacion, 'timeoutMs' => 60000]));
            $campos = $r['schema']['fields'] ?? $campos;
            $filas = $r['rows'] ?? [];
            $pagina = $r['pageToken'] ?? null;
        }
        if (! ($r['jobComplete'] ?? true)) {
            throw new RuntimeException('La consulta de BigQuery tardó demasiado. Intenta con un rango más corto.');
        }
        while ($pagina) {
            $r = $this->get("/projects/{$this->proyectoConsultas}/queries/{$jobId}", array_filter(['location' => $ubicacion, 'pageToken' => $pagina]));
            $filas = [...$filas, ...($r['rows'] ?? [])];
            $pagina = $r['pageToken'] ?? null;
        }

        $nombres = array_map(fn ($c) => $c['name'], $campos);

        return array_map(fn ($fila) => array_combine($nombres, array_map(fn ($c) => $c['v'] ?? null, $fila['f'])), $filas);
    }

    private function get(string $ruta, array $query = []): array
    {
        return $this->responder(fn () => $this->http()->get(self::BASE.$ruta, $query));
    }

    private function post(string $ruta, array $cuerpo): array
    {
        return $this->responder(fn () => $this->http()->post(self::BASE.$ruta, $cuerpo));
    }

    private function http()
    {
        return Http::withToken(($this->token)())
            ->retry(3, fn (int $i) => [1000, 3000][$i - 1] ?? 3000, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503], true)), throw: false);
    }

    /** Errores de BigQuery en español y sin detalles internos de más. */
    private function responder(Closure $peticion): array
    {
        $r = $peticion();
        if ($r->successful()) {
            return $r->json() ?? [];
        }

        $mensaje = $r->json('error.message') ?? $r->body();
        throw new RuntimeException(match ($r->status()) {
            403 => 'Sin permiso en BigQuery: la cuenta de servicio del dashboard necesita lector en el dataset y BigQuery Job User en el proyecto de consultas. ('.$mensaje.')',
            // BigQuery responde 404 igual si no existe que si no hay permiso de verlo.
            404 => 'No se encontró en BigQuery, o la cuenta de servicio no tiene permiso de verlo (BigQuery responde igual en los dos casos). Revisa el nombre y que tenga lector en el dataset. ('.$mensaje.')',
            400 => 'BigQuery rechazó la consulta: '.$mensaje,
            default => "BigQuery respondió {$r->status()}: {$mensaje}",
        });
    }
}
