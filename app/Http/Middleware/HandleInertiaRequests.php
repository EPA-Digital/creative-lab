<?php

namespace App\Http\Middleware;

use App\Models\Cuenta;
use App\Services\Ingesta\ReglasNomenclatura;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    /**
     * Solo los campos que el front realmente usa (auth-prompt.md Fase 6)
     * -- nunca el modelo completo. `$hidden` en User ya excluye password/
     * google_id/totp_* de la serialización por defecto, pero compartir el
     * modelo entero de todas formas expondría cualquier campo sensible
     * que se agregue después y alguien olvide sumar a $hidden. only()
     * fuerza a decidir explícitamente qué campo nuevo se comparte.
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    ...$request->user()->only(['id', 'name', 'email', 'rol', 'email_verified_at']),
                    // Perfil visible (puesto, color, foto) -- ver User::perfilPublico().
                    ...$request->user()->perfilPublico(),
                ] : null,
                // Solo el booleano, nunca totp_* -- lo usa el aviso
                // "Aún no tienes la verificación en dos pasos" (Aviso2fa.vue).
                'tiene_2fa' => $request->user()?->tieneTotpConfirmado() ?? false,
            ],
            // Multi-cuenta: la cuenta en la que está (para armar las URLs
            // /{cuenta}/pais/{pais}/... y mostrarla en el nav) y las que
            // puede ver, con sus países, para cambiar de cuenta y de país.
            'cuentaActual' => fn () => $this->contextoCuentas($request)['actual'],
            'misCuentas' => fn () => $this->contextoCuentas($request)['todas'],
        ];
    }

    /**
     * Una sola consulta por petición para los dos props (once()).
     *
     * @return array{actual: array<string, mixed>|null, todas: list<array<string, mixed>>}
     */
    private function contextoCuentas(Request $request): array
    {
        return once(function () use ($request) {
            $usuario = $request->user();
            if (! $usuario) {
                return ['actual' => null, 'todas' => []];
            }

            $cuentas = $usuario->cuentas()->where('activa', true)
                ->with(['cliente:id,nombre', 'paises:id,codigo'])
                ->orderBy('nombre')
                ->get(['cuentas.id', 'cliente_id', 'slug', 'nombre', 'color', 'logo_url', 'actualizado_en', 'nomenclatura']);
            $slug = $request->session()->get(EnsureAccesoPais::SESION_CUENTA_ACTUAL);
            $actual = $cuentas->firstWhere('slug', $slug)
                ?? $cuentas->firstWhere('slug', Cuenta::SLUG_TADA)
                ?? $cuentas->first();

            // Países de cada cuenta que el usuario también tiene, en el orden
            // de config/paises.php (mismo criterio que LandingController).
            $codigosUsuario = $usuario->paises()->pluck('codigo')->all();
            $config = collect(config('paises'))->filter(fn ($c) => $c['habilitado'] ?? false);
            $paisesDe = fn (Cuenta $c) => $config
                ->filter(fn ($p) => in_array($p['codigo'], $codigosUsuario, true) && $c->paises->contains('codigo', $p['codigo']))
                ->map(fn ($p, $id) => ['id' => $id, 'codigo' => $p['codigo'], 'nombre' => $p['nombre']])
                ->values();

            return [
                'actual' => $actual ? [
                    'slug' => $actual->slug,
                    'nombre' => $actual->nombre,
                    'cliente' => $actual->cliente?->nombre,
                    'color' => $actual->color,
                    'logo' => $actual->logoSrc(),
                    'otras' => $cuentas->count() - 1,
                    // Clasificación propia (ReglasNomenclatura): cómo llama la
                    // cuenta a cada etapa (AWA -> "See") y sus tipos de campaña.
                    'etapas' => ReglasNomenclatura::nombresDeEtapas($actual->nomenclatura),
                    'tiposCampania' => collect($actual->nomenclatura['tipos_campania'] ?? [])->map(fn ($t) => ['nombre' => $t['nombre'], 'eje' => $t['eje']])->values(),
                ] : null,
                'todas' => $cuentas->map(fn (Cuenta $c) => [
                    'slug' => $c->slug,
                    'nombre' => $c->nombre,
                    'cliente' => $c->cliente?->nombre,
                    'color' => $c->color,
                    'logo' => $c->logoSrc(),
                    'paises' => $paisesDe($c),
                ])->values()->all(),
            ];
        });
    }
}
