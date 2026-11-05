<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelvePaisDeContexto;
use App\Models\AppsflyerApp;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\CuentaCredencial;
use App\Models\CuentaPublicitaria;
use App\Models\Pais;
use App\Services\Auditoria;
use App\Services\Ia\AnthropicApiClient;
use App\Services\Ia\ProponerNomenclaturaService;
use App\Services\Ingesta\AppsFlyerApiClient;
use App\Services\Ingesta\BigQueryClient;
use App\Services\Ingesta\EnriquecedorAppsFlyerBigQuery;
use App\Services\Ingesta\FormulaSheets;
use App\Services\Ingesta\ReglasNomenclatura;
use App\Services\Ingesta\ValidadorCuentaPublicitaria;
use App\Support\CuentaActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Alta y edición de cuentas (multi-cuenta, diseño "Cuentas — Dashboard de
 * creativos" 2026-11-03). Lo hace la persona de EPA que lleva la cuenta:
 * datos de la cuenta, países y conexiones (cuentas publicitarias de Meta /
 * TikTok y apps de AppsFlyer) en un solo formulario.
 *
 * Una cuenta publicitaria solo puede estar en UNA cuenta del dashboard
 * (unique global plataforma + id_externo): si no, alguien podría traer los
 * datos de otro cliente a la suya.
 *
 * Credenciales: cada cuenta pone su propio token de AppsFlyer y sus eventos
 * (no hay token general). El token se prueba contra una de sus apps antes de
 * guardarlo y nunca vuelve al navegador -- solo los últimos 4 caracteres.
 *
 * Nomenclatura: con 5–10 nombres reales la IA propone las reglas
 * (proponerNomenclatura), se prueban contra los ejemplos (probarNomenclatura)
 * y se guardan con la cuenta. Sin reglas, la cuenta usa el clasificador de
 * TaDa.
 */
class CuentasController extends Controller
{
    use ResuelvePaisDeContexto;

    public function index(Request $request): Response
    {
        $cuentas = Cuenta::with(['cliente:id,nombre', 'paises:id,codigo', 'credenciales'])
            ->withCount([
                'cuentasPublicitarias as conexiones' => fn ($q) => $q->where('activa', true),
            ])
            ->orderBy('nombre')
            ->get()
            ->map(fn (Cuenta $c) => $this->resumen($c));

        return Inertia::render('Cuentas/Index', [
            'pais' => $this->paisDeContexto($request),
            'cuentas' => $cuentas,
            'catalogo' => $this->catalogo(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Cuentas/Form', [
            'pais' => $this->paisDeContexto($request),
            'cuenta' => null,
            'catalogo' => $this->catalogo(),
            'puedeDesactivar' => false,
        ]);
    }

    public function edit(Request $request, Cuenta $cuenta): Response
    {
        $cuenta->load(['cliente:id,nombre', 'paises:id,codigo']);

        return Inertia::render('Cuentas/Form', [
            'pais' => $this->paisDeContexto($request),
            'cuenta' => [
                ...$this->resumen($cuenta),
                'fuente_ga4' => $cuenta->fuente_ga4,
                'fuente_mmp' => $cuenta->fuente_mmp,
                'objetivos' => $cuenta->objetivos,
                'conexiones' => $cuenta->cuentasPublicitarias()->with('pais:id,codigo')->orderBy('id')->get()
                    ->map(fn (CuentaPublicitaria $c) => [
                        'id' => $c->id, 'pais' => $c->pais->codigo, 'plataforma' => $c->plataforma,
                        'id_externo' => $c->id_externo, 'nombre' => $c->nombre, 'activa' => $c->activa,
                    ]),
                'apps' => $cuenta->appsflyerApps()->with('pais:id,codigo')->orderBy('id')->get()
                    ->map(fn (AppsflyerApp $a) => [
                        'id' => $a->id, 'pais' => $a->pais->codigo, 'plataforma' => $a->plataforma,
                        'app_id' => $a->app_id, 'nombre' => $a->nombre,
                    ]),
                'credenciales' => [
                    'appsflyer' => $this->credencialParaFormulario($cuenta->credencial('appsflyer')),
                ],
                'nomenclatura' => $cuenta->nomenclatura,
                'nomenclatura_ejemplos' => $cuenta->nomenclatura_ejemplos,
            ],
            'catalogo' => $this->catalogo(),
            'puedeDesactivar' => $request->user()->can('desactivar-cuentas'),
        ]);
    }

    public function store(Request $request, ValidadorCuentaPublicitaria $validador): JsonResponse
    {
        $data = $this->validar($request);
        $nuevas = $this->validarConexionesNuevas($data['conexiones_nuevas'] ?? [], $validador);
        $validadoEn = $this->validarTokenAppsFlyer($data);

        $cuenta = DB::transaction(function () use ($data, $nuevas, $validadoEn, $request) {
            $cuenta = Cuenta::create([
                ...$this->atributos($data),
                'slug' => $this->slugLibre($data['nombre']),
            ]);
            $this->guardarRelaciones($cuenta, $data, $nuevas);
            $this->guardarCredencialAppsFlyer($cuenta, $data, $validadoEn, $request->user()->id);
            // Quien la crea la ve; a los demás se les asigna desde Usuarios.
            $request->user()->cuentas()->syncWithoutDetaching([$cuenta->id]);

            return $cuenta;
        });

        Auditoria::registrar('cuenta.creada', $request->user(), detalle: ['cuenta_id' => $cuenta->id, 'nombre' => $cuenta->nombre]);
        $this->auditarCredencial($request, $cuenta, $data);

        return response()->json(['ok' => true, 'slug' => $cuenta->slug], 201);
    }

    public function update(Request $request, Cuenta $cuenta, ValidadorCuentaPublicitaria $validador): JsonResponse
    {
        $data = $this->validar($request, $cuenta);
        $nuevas = $this->validarConexionesNuevas($data['conexiones_nuevas'] ?? [], $validador);
        $validadoEn = $this->validarTokenAppsFlyer($data, $cuenta);

        DB::transaction(function () use ($cuenta, $data, $nuevas, $validadoEn, $request) {
            $cuenta->update($this->atributos($data));
            $this->guardarRelaciones($cuenta, $data, $nuevas);
            $this->guardarCredencialAppsFlyer($cuenta, $data, $validadoEn, $request->user()->id);

            foreach ($data['conexiones_activas'] ?? [] as $id => $activa) {
                $cuenta->cuentasPublicitarias()->whereKey($id)->update(['activa' => (bool) $activa]);
            }
        });

        Auditoria::registrar('cuenta.editada', $request->user(), detalle: ['cuenta_id' => $cuenta->id]);
        $this->auditarCredencial($request, $cuenta, $data);

        return response()->json(['ok' => true, 'slug' => $cuenta->slug]);
    }

    public function desactivar(Request $request, Cuenta $cuenta): JsonResponse
    {
        $request->validate(['confirmacion' => ['required', 'in:'.$cuenta->nombre]], [
            'confirmacion.in' => "Para desactivar, escribe exactamente {$cuenta->nombre}.",
            'confirmacion.required' => "Para desactivar, escribe exactamente {$cuenta->nombre}.",
        ]);

        $cuenta->update(['activa' => false]);
        Auditoria::registrar('cuenta.desactivada', $request->user(), detalle: ['cuenta_id' => $cuenta->id]);

        return response()->json(['ok' => true]);
    }

    public function reactivar(Request $request, Cuenta $cuenta): JsonResponse
    {
        $cuenta->update(['activa' => true]);
        Auditoria::registrar('cuenta.reactivada', $request->user(), detalle: ['cuenta_id' => $cuenta->id]);

        return response()->json(['ok' => true]);
    }

    /**
     * La IA propone reglas a partir del cuestionario (tipos de campaña,
     * etapas de funnel, ejemplos por nivel); se devuelven ya probadas contra
     * esos mismos ejemplos para que la persona las revise.
     */
    public function proponerNomenclatura(Request $request): JsonResponse
    {
        $data = $this->validarCuestionario($request, [
            'nombre' => ['nullable', 'string', 'max:120'],
            // Catálogo de creativos ya pegado: se conserva tal cual.
            'catalogo' => ['nullable', 'array', 'max:'.ReglasNomenclatura::MAX_CATALOGO],
        ]);

        try {
            $servicio = new ProponerNomenclaturaService(AnthropicApiClient::fromConfig());
            $reglas = $servicio->proponer(($data['nombre'] ?? null) ?: 'Sin nombre', $data);
            if (! empty($data['catalogo'])) {
                $reglas = ReglasNomenclatura::normalizar([...$reglas, 'arte' => [...($reglas['arte'] ?? []), 'catalogo' => $data['catalogo']]]);
            }
        } catch (RuntimeException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['reglas' => $reglas, ...$this->vistaPrevia($reglas, $data)]);
    }

    /**
     * Prueba reglas contra los ejemplos, sin IA. Sin `reglas`, las arma solo
     * con las siglas del cuestionario.
     */
    public function probarNomenclatura(Request $request): JsonResponse
    {
        $data = $this->validarCuestionario($request, ['reglas' => ['nullable', 'array']]);

        try {
            $reglas = ! empty($data['reglas'])
                ? ReglasNomenclatura::normalizar($data['reglas'])
                : ProponerNomenclaturaService::desdeCuestionario($data);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reglas' => $e->getMessage()]);
        }

        return response()->json(['reglas' => $reglas, ...$this->vistaPrevia($reglas, $data)]);
    }

    /** =IFS(REGEXMATCH(...)) de Sheets -> reglas patrón → valor. */
    public function nomenclaturaDesdeFormula(Request $request): JsonResponse
    {
        $data = $request->validate(['formula' => ['required', 'string', 'max:5000']], [
            'formula.required' => 'Pega la fórmula de Sheets.',
        ]);

        try {
            return response()->json(['reglas' => FormulaSheets::convertir($data['formula'])]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['formula' => $e->getMessage()]);
        }
    }

    /**
     * Cuestionario de clasificación (el mismo que se guarda en
     * nomenclatura_ejemplos): tipos de campaña y etapas de funnel con sus
     * siglas y ejemplos, y nombres reales por nivel.
     *
     * @return array<string, mixed>
     */
    private function validarCuestionario(Request $request, array $extra = []): array
    {
        $data = $request->validate([...$extra, ...self::reglasCuestionario('')], self::mensajesCuestionario());

        $total = count($data['campanias'] ?? []) + count($data['conjuntos'] ?? []) + count($data['anuncios'] ?? [])
            + collect([...($data['tipos'] ?? []), ...($data['etapas'] ?? [])])->sum(fn ($x) => count($x['ejemplos'] ?? []));
        if ($total < 3) {
            throw ValidationException::withMessages(['anuncios' => 'Pega al menos 3 nombres reales (campañas, conjuntos o anuncios) para poder probar.']);
        }

        return $data;
    }

    /** Reglas de validación del cuestionario, con prefijo (ej. "nomenclatura_ejemplos."). */
    private static function reglasCuestionario(string $prefijo): array
    {
        $nombres = fn (int $max) => ['array', "max:{$max}"];

        return [
            "{$prefijo}tipos" => $nombres(ReglasNomenclatura::MAX_TIPOS),
            "{$prefijo}tipos.*.nombre" => ['required', 'string', 'max:40'],
            "{$prefijo}tipos.*.eje" => ['required', Rule::in(ReglasNomenclatura::EJES)],
            "{$prefijo}tipos.*.siglas" => ['nullable', 'string', 'max:200'],
            "{$prefijo}tipos.*.ejemplos" => $nombres(10),
            "{$prefijo}tipos.*.ejemplos.*" => ['string', 'max:500'],
            "{$prefijo}etapas" => $nombres(ReglasNomenclatura::MAX_ETAPAS),
            "{$prefijo}etapas.*.nombre" => ['required', 'string', 'max:40'],
            "{$prefijo}etapas.*.equivalente" => ['required', Rule::in(ReglasNomenclatura::EQUIVALENTES)],
            "{$prefijo}etapas.*.siglas" => ['nullable', 'string', 'max:200'],
            "{$prefijo}etapas.*.ejemplos" => $nombres(10),
            "{$prefijo}etapas.*.ejemplos.*" => ['string', 'max:500'],
            "{$prefijo}campanias" => $nombres(30),
            "{$prefijo}campanias.*" => ['string', 'max:500'],
            "{$prefijo}conjuntos" => $nombres(30),
            "{$prefijo}conjuntos.*" => ['string', 'max:500'],
            "{$prefijo}anuncios" => $nombres(30),
            "{$prefijo}anuncios.*" => ['string', 'max:500'],
            "{$prefijo}notas" => ['nullable', 'string', 'max:3000'],
        ];
    }

    private static function mensajesCuestionario(): array
    {
        return [
            'tipos.*.nombre.required' => 'Ponle nombre a cada tipo de campaña.',
            'etapas.*.nombre.required' => 'Ponle nombre a cada etapa del funnel.',
            'etapas.*.equivalente.required' => 'Elige a qué equivale cada etapa.',
            'anuncios.max' => 'Con 30 anuncios basta.',
            'campanias.max' => 'Con 30 campañas basta.',
            'conjuntos.max' => 'Con 30 conjuntos basta.',
        ];
    }

    /**
     * Cada ejemplo leído con las reglas, y para los ejemplos etiquetados
     * (los de cada tipo y etapa) si salió lo esperado: "5 de 5 ejemplos de
     * See se leen como See".
     *
     * @return array{vista_previa: list<array<string, mixed>>, resumen: array{tipos: list<array<string, mixed>>, etapas: list<array<string, mixed>>}}
     */
    private function vistaPrevia(array $reglas, array $data): array
    {
        $filas = [];
        $fila = function (string $nivel, string $nombre, array $r, ?string $esperaEtapa = null, ?string $esperaTipo = null) use (&$filas) {
            $ok = ($esperaEtapa === null || mb_strtolower((string) $r['funnelPropio']) === mb_strtolower($esperaEtapa))
                && ($esperaTipo === null || mb_strtolower((string) $r['tipoCampaniaNombre']) === mb_strtolower($esperaTipo));
            $filas[] = [
                'tipo' => $nivel,
                'nombre' => $nombre,
                'esperado' => $esperaEtapa ?? $esperaTipo,
                'ok' => $ok,
                'es_de_la_cuenta' => $r['esDeLaCuenta'],
                'plataforma' => $r['plataforma'],
                'funnel' => $r['funnel'],
                'funnel_propio' => $r['funnelPropio'],
                'tipo_campania' => $r['tipoCampaniaNombre'],
                'formato' => $r['formato'],
                'dimensiones' => $r['dimensiones'],
                'arte' => $nivel === 'anuncio' ? $r['arte'] : null,
                'reglas_aplicadas' => $r['reglasAplicadas'],
            ];

            return $ok;
        };

        $resumen = ['tipos' => [], 'etapas' => []];
        foreach ([['tipos', 'tipo'], ['etapas', 'etapa']] as [$clave, $que]) {
            foreach ($data[$clave] ?? [] as $item) {
                $aciertos = 0;
                foreach ($item['ejemplos'] ?? [] as $ejemplo) {
                    $r = ReglasNomenclatura::parsear($reglas, $ejemplo, null);
                    $aciertos += (int) $fila('campania', $ejemplo, $r, $que === 'etapa' ? $item['nombre'] : null, $que === 'tipo' ? $item['nombre'] : null);
                }
                $resumen[$clave][] = ['nombre' => $item['nombre'], 'aciertos' => $aciertos, 'total' => count($item['ejemplos'] ?? [])];
            }
        }

        foreach ($data['campanias'] ?? [] as $n) {
            $fila('campania', $n, ReglasNomenclatura::parsear($reglas, $n, null));
        }
        foreach ($data['conjuntos'] ?? [] as $n) {
            $fila('conjunto', $n, ReglasNomenclatura::parsear($reglas, null, null, $n));
        }
        foreach ($data['anuncios'] ?? [] as $n) {
            $fila('anuncio', $n, ReglasNomenclatura::parsear($reglas, null, $n));
        }

        return ['vista_previa' => $filas, 'resumen' => $resumen];
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?Cuenta $cuenta = null): array
    {
        $codigos = Pais::pluck('codigo')->all();

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'cliente' => ['required', 'string', 'max:120'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'servicios' => ['array'],
            'servicios.*' => [Rule::in(Cuenta::SERVICIOS)],
            'objetivos' => ['required', 'array', 'min:1'],
            'objetivos.*' => [Rule::in(Cuenta::OBJETIVOS)],
            'medios' => ['required', 'array', 'min:1'],
            'medios.*' => [Rule::in(array_keys(Cuenta::MEDIOS))],
            'fuente_ga4' => ['boolean'],
            'fuente_mmp' => ['boolean'],
            'venta_real' => ['required', Rule::in(Cuenta::VENTA_REAL)],
            'multi_pais' => ['boolean'],
            'paises' => ['array'],
            'paises.*' => [Rule::in($codigos)],
            'conexiones_nuevas' => ['array'],
            'conexiones_nuevas.*.pais' => ['required', Rule::in($codigos)],
            'conexiones_nuevas.*.plataforma' => ['required', Rule::in(CuentaPublicitaria::PLATAFORMAS)],
            'conexiones_nuevas.*.id_externo' => ['required', 'string', 'max:50', 'regex:/^(act_)?\d+$/i'],
            'conexiones_nuevas.*.nombre' => ['nullable', 'string', 'max:150'],
            'apps_nuevas' => ['array'],
            'apps_nuevas.*.pais' => ['required', Rule::in($codigos)],
            'apps_nuevas.*.plataforma' => ['required', Rule::in(['ios', 'android'])],
            'apps_nuevas.*.app_id' => ['required', 'string', 'max:100'],
            'apps_nuevas.*.nombre' => ['nullable', 'string', 'max:100'],
            'conexiones_activas' => ['array'],
            'conexiones_activas.*' => ['boolean'],
            // Vacío = no cambia el token guardado; quitar = lo borra.
            'appsflyer_token' => ['nullable', 'string', 'max:2000'],
            'appsflyer_quitar_token' => ['boolean'],
            'appsflyer_evento_principal' => ['nullable', 'string', 'max:100'],
            'appsflyer_evento_secundario' => ['nullable', 'string', 'max:100'],
            // De dónde sale AppsFlyer: API (token) o BigQuery (Data Locker de EPA).
            'appsflyer_origen' => ['nullable', Rule::in(['api', 'bigquery'])],
            'appsflyer_bq_proyecto' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'appsflyer_bq_dataset' => ['nullable', 'string', 'max:1024', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'appsflyer_bq_tablas' => ['nullable', 'array'],
            'appsflyer_bq_tablas.*' => ['nullable', 'string', 'max:1024', 'regex:/^[A-Za-z0-9_\-]*$/'],
            'appsflyer_zona_horaria' => ['nullable', 'timezone:all'],
            // Reglas ya revisadas en la vista previa; null = clasificador de TaDa.
            'nomenclatura' => ['nullable', 'array'],
            // El cuestionario de clasificación tal cual, para volver a proponer o probar.
            'nomenclatura_ejemplos' => ['nullable', 'array'],
            ...self::reglasCuestionario('nomenclatura_ejemplos.'),
        ], [
            'nombre.required' => 'Escribe el nombre de la cuenta.',
            'cliente.required' => 'Elige un cliente o crea uno nuevo.',
            'color.regex' => 'Usa un color hex de 6 dígitos, como #9184d9.',
            'objetivos.required' => 'Elige al menos un objetivo para saber qué métricas mostrarte.',
            'objetivos.min' => 'Elige al menos un objetivo para saber qué métricas mostrarte.',
            'medios.required' => 'Elige al menos un medio.',
            'medios.min' => 'Elige al menos un medio.',
            'conexiones_nuevas.*.id_externo.regex' => 'Usa solo números, sin espacios ni guiones (en Meta puede llevar "act_" adelante).',
            'conexiones_nuevas.*.id_externo.required' => 'Escribe el ID de la cuenta publicitaria.',
            'apps_nuevas.*.app_id.required' => 'Escribe el ID de la app.',
        ]);

        $errores = [];
        $tieneApp = in_array('App', $data['servicios'] ?? [], true);
        if (! $tieneApp && in_array('Instalaciones', $data['objetivos'], true)) {
            $errores['objetivos'] = 'Instalaciones requiere el tipo de servicio App.';
        }
        if (! $tieneApp && ($data['fuente_mmp'] ?? false)) {
            $errores['fuente_mmp'] = 'AppsFlyer requiere el tipo de servicio App.';
        }
        if (($data['multi_pais'] ?? false) && count($data['paises'] ?? []) < 2) {
            $errores['paises'] = 'Elige al menos dos países, o apaga la opción.';
        }
        $porBigQuery = ($data['appsflyer_origen'] ?? 'api') === 'bigquery';
        if (($data['fuente_mmp'] ?? false) && $porBigQuery && (blank($data['appsflyer_bq_proyecto'] ?? null) || blank($data['appsflyer_bq_dataset'] ?? null))) {
            $errores['appsflyer_bq_dataset'] = 'Escribe el proyecto y el dataset de BigQuery donde está AppsFlyer.';
        }
        $tendraToken = $porBigQuery || filled($data['appsflyer_token'] ?? null)
            || (! ($data['appsflyer_quitar_token'] ?? false) && $cuenta?->credencial('appsflyer')?->tieneToken());
        if (($data['fuente_mmp'] ?? false) && $tendraToken && blank($data['appsflyer_evento_principal'] ?? null)) {
            $errores['appsflyer_evento_principal'] = 'Escribe el nombre exacto del evento principal, como aparece en AppsFlyer.';
        }
        // TaDa no usa clasificación propia (ver Nomenclatura): se ignora.
        if ($cuenta?->slug === Cuenta::SLUG_TADA) {
            unset($data['nomenclatura'], $data['nomenclatura_ejemplos']);
        }
        if (! empty($data['nomenclatura'])) {
            try {
                $data['nomenclatura'] = ReglasNomenclatura::normalizar($data['nomenclatura']);
            } catch (InvalidArgumentException $e) {
                $errores['nomenclatura'] = $e->getMessage();
            }
        }
        $nombreRepetido = Cuenta::where('nombre', $data['nombre'])->when($cuenta, fn ($q) => $q->whereKeyNot($cuenta->id))->exists();
        if ($nombreRepetido) {
            $errores['nombre'] = 'Ya existe una cuenta con ese nombre.';
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        return $data;
    }

    /**
     * Cada cuenta publicitaria nueva: no repetida en NINGUNA cuenta del
     * dashboard (sin el filtro global, a propósito) y legible con el token
     * de EPA. Devuelve las filas listas para guardar.
     *
     * @param  list<array<string, string|null>>  $conexiones
     * @return list<array<string, string|null>>
     */
    private function validarConexionesNuevas(array $conexiones, ValidadorCuentaPublicitaria $validador): array
    {
        $errores = [];
        $vistas = [];
        foreach ($conexiones as $i => $c) {
            $idExterno = ValidadorCuentaPublicitaria::normalizarId($c['id_externo']);
            $clave = "{$c['plataforma']}:{$idExterno}";
            $campo = "conexiones_nuevas.{$i}.id_externo";

            if (isset($vistas[$clave]) || CuentaPublicitaria::withoutGlobalScope('cuenta')->where('plataforma', $c['plataforma'])->where('id_externo', $idExterno)->exists()) {
                $errores[$campo] = 'Esa cuenta publicitaria ya está registrada en el dashboard.';

                continue;
            }
            $vistas[$clave] = true;

            try {
                $nombreRemoto = $validador->nombreRemoto($c['plataforma'], $idExterno);
            } catch (InvalidArgumentException $e) {
                $errores[$campo] = $e->getMessage();

                continue;
            }

            $conexiones[$i] = [
                ...$c,
                'id_externo' => $idExterno,
                'nombre' => ($c['nombre'] ?? null) ?: ($nombreRemoto ?? ($c['plataforma'] === 'meta' ? 'Meta' : 'TikTok')." {$idExterno}"),
            ];
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        return array_values($conexiones);
    }

    /**
     * Token nuevo de AppsFlyer: se prueba contra una app de la cuenta (las
     * ya guardadas o las que vienen en el formulario) antes de guardarlo.
     * Sin apps todavía, se guarda sin validar (validado_en null) y se valida
     * cuando haya apps. Devuelve cuándo quedó validado.
     */
    private function validarTokenAppsFlyer(array $data, ?Cuenta $cuenta = null): ?\DateTimeInterface
    {
        $token = trim($data['appsflyer_token'] ?? '');
        if ($token === '' || ! ($data['fuente_mmp'] ?? false) || ($data['appsflyer_origen'] ?? 'api') === 'bigquery') {
            return null;
        }

        $appId = collect($data['apps_nuevas'] ?? [])->pluck('app_id')->map(fn ($a) => trim($a))->filter()->first()
            ?? $cuenta?->appsflyerApps()->value('app_id');
        if (! $appId) {
            return null;
        }

        try {
            (new AppsFlyerApiClient($token))->probarAcceso($appId);
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'appsflyer_token' => "AppsFlyer no dio acceso a {$appId} con ese token. Revisa que sea el token de la cuenta de AppsFlyer de este cliente.",
            ]);
        }

        return now();
    }

    private function guardarCredencialAppsFlyer(Cuenta $cuenta, array $data, ?\DateTimeInterface $validadoEn, int $usuarioId): void
    {
        $credencial = $cuenta->credencial('appsflyer') ?? new CuentaCredencial(['cuenta_id' => $cuenta->id, 'proveedor' => 'appsflyer']);
        $token = trim($data['appsflyer_token'] ?? '');

        if ($data['appsflyer_quitar_token'] ?? false) {
            $credencial->fill(['token' => null, 'ultimos4' => null, 'validado_en' => null, 'actualizado_por' => $usuarioId]);
        } elseif ($token !== '') {
            $credencial->fill(['token' => $token, 'ultimos4' => substr($token, -4), 'validado_en' => $validadoEn, 'actualizado_por' => $usuarioId]);
        }

        $porBigQuery = ($data['appsflyer_origen'] ?? 'api') === 'bigquery';
        $credencial->ajustes = array_filter([
            'evento_principal' => trim($data['appsflyer_evento_principal'] ?? '') ?: null,
            'evento_secundario' => trim($data['appsflyer_evento_secundario'] ?? '') ?: null,
            'origen' => $porBigQuery ? 'bigquery' : null,
            'bq_proyecto' => $porBigQuery ? trim($data['appsflyer_bq_proyecto'] ?? '') : null,
            'bq_dataset' => $porBigQuery ? trim($data['appsflyer_bq_dataset'] ?? '') : null,
            'bq_tablas' => $porBigQuery ? array_map(fn ($t) => trim((string) $t), $data['appsflyer_bq_tablas'] ?? []) : null,
            'zona_horaria' => $porBigQuery ? ($data['appsflyer_zona_horaria'] ?? null) : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        if ($credencial->exists || $credencial->tieneToken() || array_filter($credencial->ajustes)) {
            $credencial->save();
        }
    }

    /** Nunca el token: solo qué pasó y con qué proveedor. */
    private function auditarCredencial(Request $request, Cuenta $cuenta, array $data): void
    {
        $evento = match (true) {
            (bool) ($data['appsflyer_quitar_token'] ?? false) => 'cuenta.credencial_quitada',
            filled($data['appsflyer_token'] ?? null) => 'cuenta.credencial_guardada',
            default => null,
        };
        if ($evento) {
            Auditoria::registrar($evento, $request->user(), detalle: ['cuenta_id' => $cuenta->id, 'proveedor' => 'appsflyer']);
        }
    }

    /** @return array<string, mixed> */
    private function credencialParaFormulario(?CuentaCredencial $c): array
    {
        return [
            'tiene_token' => (bool) $c?->tieneToken(),
            'ultimos4' => $c?->ultimos4,
            'validado_en' => $c?->validado_en?->toIso8601String(),
            'actualizado_por' => $c?->actualizadoPor?->email,
            'evento_principal' => $c?->ajustes['evento_principal'] ?? null,
            'evento_secundario' => $c?->ajustes['evento_secundario'] ?? null,
            'origen' => $c?->origen() ?? 'api',
            'bq_proyecto' => $c?->ajustes['bq_proyecto'] ?? null,
            'bq_dataset' => $c?->ajustes['bq_dataset'] ?? null,
            'bq_tablas' => $c?->ajustes['bq_tablas'] ?? null,
            'zona_horaria' => $c?->ajustes['zona_horaria'] ?? null,
        ];
    }

    /**
     * "Probar conexión" de AppsFlyer por BigQuery: revisa, sin traer datos,
     * que la cuenta de servicio del dashboard vea el dataset y que las
     * tablas tengan las columnas que se leen (ver EnriquecedorAppsFlyerBigQuery).
     */
    public function probarBigQuery(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bq_proyecto' => ['required', 'string', 'max:100'],
            'bq_dataset' => ['required', 'string', 'max:1024'],
            'bq_tablas' => ['nullable', 'array'],
            'bq_tablas.*' => ['nullable', 'string', 'max:1024'],
        ], ['bq_proyecto.required' => 'Escribe el proyecto de BigQuery.', 'bq_dataset.required' => 'Escribe el dataset.']);

        try {
            return response()->json(EnriquecedorAppsFlyerBigQuery::probar(app(BigQueryClient::class), $data));
        } catch (RuntimeException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** @return array<string, mixed> */
    private function atributos(array $data): array
    {
        $tieneApp = in_array('App', $data['servicios'] ?? [], true);

        return [
            'cliente_id' => Cliente::firstOrCreate(['nombre' => trim($data['cliente'])])->id,
            'nombre' => trim($data['nombre']),
            'color' => strtolower($data['color']),
            'servicios' => array_values($data['servicios'] ?? []),
            'objetivos' => array_values($data['objetivos']),
            'medios' => array_values($data['medios']),
            'fuente_ga4' => (bool) ($data['fuente_ga4'] ?? false),
            'fuente_mmp' => $tieneApp && ($data['fuente_mmp'] ?? false),
            'venta_real' => $data['venta_real'],
            'multi_pais' => (bool) ($data['multi_pais'] ?? false),
            ...(array_key_exists('nomenclatura', $data) ? ['nomenclatura' => $data['nomenclatura'] ?: null] : []),
            ...(array_key_exists('nomenclatura_ejemplos', $data) ? ['nomenclatura_ejemplos' => $data['nomenclatura_ejemplos'] ?: null] : []),
        ];
    }

    /**
     * Países (sin multi-país, solo México) y conexiones nuevas, dentro de la
     * cuenta -- CuentaActual::usar para que nazcan con su cuenta_id.
     */
    private function guardarRelaciones(Cuenta $cuenta, array $data, array $nuevas): void
    {
        $codigos = ($data['multi_pais'] ?? false) ? $data['paises'] : ['MX'];
        $paisIdPorCodigo = Pais::whereIn('codigo', array_unique([...$codigos, ...array_column($nuevas, 'pais'), ...array_column($data['apps_nuevas'] ?? [], 'pais')]))
            ->pluck('id', 'codigo');
        $cuenta->paises()->sync($paisIdPorCodigo->only($codigos)->values());

        CuentaActual::usar($cuenta, function () use ($cuenta, $data, $nuevas, $paisIdPorCodigo) {
            foreach ($nuevas as $c) {
                CuentaPublicitaria::create([
                    'pais_id' => $paisIdPorCodigo[$c['pais']],
                    'plataforma' => $c['plataforma'],
                    'id_externo' => $c['id_externo'],
                    'nombre' => $c['nombre'],
                    'tipo' => 'tada',
                    'activa' => true,
                    'cuenta_en_venta_real' => $cuenta->venta_real !== 'no',
                ]);
            }
            foreach ($data['apps_nuevas'] ?? [] as $a) {
                AppsflyerApp::updateOrCreate(
                    ['pais_id' => $paisIdPorCodigo[$a['pais']], 'plataforma' => $a['plataforma']],
                    ['app_id' => trim($a['app_id']), 'nombre' => $a['nombre'] ?? null],
                );
            }
        });
    }

    /** Primer segmento de URLs que ya existen: una cuenta no puede llamarse así. */
    private const SLUGS_RESERVADOS = ['pais', 'cuentas', 'usuarios', 'profile', 'login', 'logout', 'invitacion', 'invitaciones', 'seguridad', 'auth', 'up', 'build', 'storage', 'password', 'recuperar', '2fa'];

    private function slugLibre(string $nombre): string
    {
        $base = Str::slug($nombre) ?: 'cuenta';
        $slug = in_array($base, self::SLUGS_RESERVADOS, true) ? "{$base}-2" : $base;
        for ($n = 2; Cuenta::where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function resumen(Cuenta $c): array
    {
        return [
            'id' => $c->id,
            'slug' => $c->slug,
            'nombre' => $c->nombre,
            'cliente' => $c->cliente?->nombre,
            'color' => $c->color,
            'logo' => $c->logoSrc(),
            'servicios' => $c->servicios ?? [],
            'medios' => $c->medios ?? [],
            'venta_real' => $c->venta_real,
            'multi_pais' => $c->multi_pais,
            'paises' => $c->paises->pluck('codigo')->values(),
            'activa' => $c->activa,
            'conexiones' => $c->conexiones ?? null,
            // AppsFlyer encendido pero sin token: la lista lo marca.
            'sin_token_appsflyer' => $c->fuente_mmp && ! $c->credenciales->firstWhere('proveedor', 'appsflyer')?->tieneToken(),
        ];
    }

    /** Opciones del formulario y la lista. */
    private function catalogo(): array
    {
        $banderas = collect(config('paises'))->mapWithKeys(fn (array $c) => [$c['codigo'] => $c['bandera_gradiente'] ?? null]);

        return [
            'servicios' => Cuenta::SERVICIOS,
            'objetivos' => Cuenta::OBJETIVOS,
            'medios' => collect(Cuenta::MEDIOS)->map(fn ($nombre, $clave) => [
                'clave' => $clave, 'nombre' => $nombre, 'conectado' => in_array($clave, Cuenta::MEDIOS_CONECTADOS, true),
            ])->values(),
            'paises' => Pais::orderBy('nombre')->get(['codigo', 'nombre'])->map(fn (Pais $p) => [
                'codigo' => $p->codigo, 'nombre' => $p->nombre, 'bandera' => $banderas[$p->codigo] ?? null,
            ]),
            'clientes' => Cliente::withCount('cuentas')->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Cliente $c) => ['nombre' => $c->nombre, 'cuentas' => $c->cuentas_count]),
        ];
    }
}
