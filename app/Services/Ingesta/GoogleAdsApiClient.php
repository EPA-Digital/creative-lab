<?php

namespace App\Services\Ingesta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * API de Google Ads (REST, 2026-11-26). Auth: OAuth del cliente de escritorio
 * "zx-dashboard-api" (secreto ZxDashboardGoogleAdsOAuth, el JSON tal cual lo
 * baja la consola) + refresh token de una persona con acceso a las cuentas +
 * developer token de la MCC.
 *
 * Las cuentas de TaDa cuelgan de distintas MCC; cada consulta a una cuenta
 * hija necesita el header login-customer-id con la MCC por la que se llega.
 * Se averigua solo (listAccessibleCustomers + customer_client) y se guarda un
 * día en caché, así en cuentas_publicitarias basta el ID de la cuenta.
 */
class GoogleAdsApiClient
{
    private const BASE_URL = 'https://googleads.googleapis.com';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
        private readonly string $developerToken,
        private readonly string $version = 'v23',
    ) {}

    public static function fromConfig(): self
    {
        $c = config('services.google_ads');
        $oauth = json_decode((string) ($c['oauth_json'] ?? ''), true);
        $oauth = $oauth['installed'] ?? $oauth['web'] ?? $oauth ?? [];
        $clientId = $oauth['client_id'] ?? null;
        $clientSecret = $oauth['client_secret'] ?? null;
        if (! $clientId || ! $clientSecret || empty($c['refresh_token']) || empty($c['developer_token'])) {
            throw new RuntimeException('Faltan las credenciales de Google Ads (GOOGLE_ADS_OAUTH_JSON, GOOGLE_ADS_REFRESH_TOKEN, GOOGLE_ADS_DEVELOPER_TOKEN).');
        }

        return new self($clientId, $clientSecret, $c['refresh_token'], $c['developer_token'], $c['version'] ?? 'v23');
    }

    /** Solo dígitos: "123-456-7890" → "1234567890". */
    public static function normalizarId(string $id): string
    {
        return preg_replace('/\D/', '', $id);
    }

    /**
     * GAQL contra una cuenta, con todas las páginas.
     *
     * @return list<array<string, mixed>>
     */
    public function buscar(string $customerId, string $query): array
    {
        $customerId = self::normalizarId($customerId);
        $login = $this->loginDe($customerId);
        $filas = [];
        $pagina = null;
        do {
            $data = $this->post("customers/{$customerId}/googleAds:search", array_filter(['query' => $query, 'pageToken' => $pagina]), $login);
            array_push($filas, ...($data['results'] ?? []));
            $pagina = $data['nextPageToken'] ?? null;
        } while ($pagina);

        return $filas;
    }

    /** MCC (o la cuenta misma) por la que el usuario del refresh token llega a la cuenta. */
    public function loginDe(string $customerId): string
    {
        return Cache::remember("google_ads.login.{$customerId}", now()->addDay(), function () use ($customerId) {
            $raices = array_map(fn ($rn) => substr($rn, strlen('customers/')), $this->request('get', 'customers:listAccessibleCustomers')['resourceNames'] ?? []);
            if (in_array($customerId, $raices, true)) {
                return $customerId;
            }
            foreach ($raices as $raiz) {
                try {
                    $r = $this->post("customers/{$raiz}/googleAds:search", ['query' => "SELECT customer_client.id FROM customer_client WHERE customer_client.id = {$customerId}"], $raiz);
                } catch (RuntimeException) {
                    continue; // una raíz que no es MCC no tiene customer_client
                }
                if (! empty($r['results'])) {
                    return $raiz;
                }
            }
            throw new RuntimeException("el usuario de Google Ads no tiene acceso a la cuenta {$customerId}");
        });
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>
     */
    private function post(string $ruta, array $cuerpo, ?string $login): array
    {
        return $this->request('post', $ruta, $cuerpo, $login);
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>
     */
    private function request(string $metodo, string $ruta, array $cuerpo = [], ?string $login = null): array
    {
        $headers = ['developer-token' => $this->developerToken];
        if ($login) {
            $headers['login-customer-id'] = $login;
        }
        $peticion = Http::withToken($this->accessToken())->withHeaders($headers)->acceptJson()->timeout(120)
            ->retry(4, fn (int $intento) => [1000, 3000, 8000][$intento - 1] ?? 8000, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true)), throw: false);
        $url = self::BASE_URL."/{$this->version}/{$ruta}";
        $respuesta = $metodo === 'get' ? $peticion->get($url) : $peticion->post($url, $cuerpo);

        if ($respuesta->failed()) {
            $error = $respuesta->json('error') ?? [];
            $detalle = $error['details'][0]['errors'][0]['message'] ?? $error['message'] ?? $respuesta->body();
            throw new RuntimeException('Google Ads '.$respuesta->status().': '.mb_substr((string) $detalle, 0, 300));
        }

        return $respuesta->json() ?? [];
    }

    private function accessToken(): string
    {
        return Cache::remember('google_ads.token.'.md5($this->clientId.$this->refreshToken), now()->addMinutes(50), function () {
            $r = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $this->clientId, 'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken, 'grant_type' => 'refresh_token',
            ]);
            if ($r->failed() || ! $r->json('access_token')) {
                throw new RuntimeException('Google no dio access token ('.($r->json('error_description') ?? $r->json('error') ?? $r->status()).'); ¿se revocó el refresh token?');
            }

            return $r->json('access_token');
        });
    }
}
