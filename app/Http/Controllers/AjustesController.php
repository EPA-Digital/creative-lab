<?php

namespace App\Http\Controllers;

use App\Models\AppsflyerApp;
use App\Models\CuentaPublicitaria;
use App\Models\Importacion;
use App\Models\Pais;
use App\Models\TipoCambio;
use App\Services\Ingesta\ClasificadorNombres;
use App\Services\Ingesta\ValidadorCuentaPublicitaria;
use App\Support\CuentaActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Gestión de apps de AppsFlyer por país (tabla appsflyer_apps, 2026-08-12) --
 * reemplaza tener que agregar un país a mano por seeder/tinker.
 *
 * El {pais} de la URL sigue siendo solo contexto visual del rail
 * (DashboardLayout exige :pais como prop obligatoria) -- pero la
 * gestión en sí (2026-09-23, fix de scope) quedó acotada a los países
 * que el usuario tiene asignados, no a los 4 países como antes: ver/
 * editar el App ID de un país que ni siquiera puede ver en el dashboard
 * era un hueco real del scope por país.
 */
class AjustesController extends Controller
{
    public function index(string $pais, Request $request): Response
    {
        abort_unless(config("paises.{$pais}"), 404, "País \"{$pais}\" no existe en config/paises.php.");

        $paisesPermitidos = $request->user()->paises()->orderBy('nombre')->get(['paises.id', 'codigo', 'nombre']);

        $paisActualId = Pais::where('codigo', config("paises.{$pais}.codigo"))->value('id');

        return Inertia::render('Ajustes/Index', [
            'pais' => $pais,
            'paises' => $paisesPermitidos,
            'appsflyerApps' => AppsflyerApp::with('pais')
                ->whereIn('pais_id', $paisesPermitidos->pluck('id'))
                ->orderBy('pais_id')->orderBy('plataforma')->get(),
            'cuentasPublicitarias' => CuentaPublicitaria::with('pais:id,nombre')
                ->whereIn('pais_id', $paisesPermitidos->pluck('id'))
                ->orderBy('pais_id')->orderBy('plataforma')->orderBy('id')->get(),
            'puedeGestionarCuentas' => $request->user()->can('gestionar-cuentas-publicitarias'),
            // Divisas (2026-11-11): todo se reporta en USD.
            'tiposCambio' => TipoCambio::orderBy('moneda')->orderByDesc('vigente_desde')
                ->get(['id', 'moneda', 'vigente_desde', 'por_usd', 'actualizado_en']),
            // Double check (2026-10-05) -- las últimas importaciones de ESTE
            // país con conciliación, la más nueva primero.
            'campanias' => $paisActualId ? self::campaniasDelPais($paisActualId) : [],
            'conciliaciones' => Importacion::where('pais_id', $paisActualId)
                ->whereNotNull('conciliacion')
                ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', 'completado'))
                ->orderByDesc('id')
                ->limit(12)
                ->get(['id', 'origen', 'desde', 'hasta', 'creado_en', 'conciliacion']),
        ]);
    }

    /**
     * Campañas que usa el país (pedido explícito 2026-10-06), tal como
     * quedaron en los creativos importados: de qué cuenta vienen, etapa,
     * tipo (DTC/BRD, paid o no), cuántos anuncios, gasto total y en qué
     * meses aparecen. Solo lectura -- sirve para revisar que las cuentas y
     * nomenclaturas cuadren con lo que se está pautando.
     *
     * @return list<array<string, mixed>>
     */
    private static function campaniasDelPais(int $paisId): array
    {
        return DB::table('creativos')
            ->join('resultados', 'resultados.creativo_id', '=', 'creativos.id')
            ->leftJoin('cuentas_publicitarias', 'cuentas_publicitarias.id', '=', 'creativos.cuenta_publicitaria_id')
            ->where('creativos.cuenta_id', CuentaActual::id())
            ->where('creativos.pais_id', $paisId)
            ->whereNotNull('creativos.nombre_campania')
            ->groupBy('creativos.nombre_campania', 'creativos.plataforma')
            ->orderByDesc(DB::raw('MAX(resultados.mes)'))
            ->orderByDesc(DB::raw('SUM(resultados.cost)'))
            ->get([
                'creativos.nombre_campania',
                'creativos.plataforma',
                DB::raw('MIN(creativos.funnel) as funnel'),
                DB::raw('MIN(creativos.tipo_cuenta) as tipo_cuenta'),
                DB::raw('MIN(cuentas_publicitarias.nombre) as cuenta'),
                DB::raw('COUNT(DISTINCT creativos.id) as anuncios'),
                DB::raw('SUM(resultados.cost) as costo'),
                DB::raw('MIN(resultados.mes) as primer_mes'),
                DB::raw('MAX(resultados.mes) as ultimo_mes'),
            ])
            ->map(fn ($c) => [
                'campania' => $c->nombre_campania,
                'plataforma' => $c->plataforma,
                'funnel' => $c->funnel,
                'tipoCuenta' => $c->tipo_cuenta,
                'paid' => ! ClasificadorNombres::esDtcNoPaid($c->nombre_campania),
                'cuenta' => $c->cuenta,
                'anuncios' => (int) $c->anuncios,
                'costo' => round((float) $c->costo, 2),
                'primerMes' => $c->primer_mes,
                'ultimoMes' => $c->ultimo_mes,
            ])
            ->all();
    }

    /**
     * Alta de una cuenta publicitaria -- solo superadmin (Gate
     * 'gestionar-cuentas-publicitarias', ver routes/web.php). Se valida
     * contra la API real antes de guardar (ValidadorCuentaPublicitaria);
     * si no se tecleó nombre, se usa el que devuelve la plataforma.
     */
    public function storeCuentaPublicitaria(Request $request, string $pais, ValidadorCuentaPublicitaria $validador): JsonResponse
    {
        abort_unless(config("paises.{$pais}"), 404, "País \"{$pais}\" no existe en config/paises.php.");

        $data = $request->validate([
            'pais_id' => ['required', 'integer', Rule::in($request->user()->paises()->pluck('paises.id'))],
            'plataforma' => ['required', Rule::in(CuentaPublicitaria::PLATAFORMAS)],
            'id_externo' => ['required', 'string', 'max:50', 'regex:/^(act_)?\d+$/i'],
            'nombre' => ['nullable', 'string', 'max:150'],
            'tipo' => ['required', Rule::in(CuentaPublicitaria::TIPOS)],
            'cuenta_en_venta_real' => ['required', 'boolean'],
        ], [
            'id_externo.regex' => 'El ID de cuenta debe ser solo números (en Meta puede llevar "act_" adelante).',
        ]);

        $data['id_externo'] = ValidadorCuentaPublicitaria::normalizarId($data['id_externo']);
        // Sin el filtro de cuenta a propósito: una cuenta publicitaria solo
        // puede estar en UNA cuenta del dashboard, la que sea -- si no,
        // alguien podría traer los datos de otro cliente a la suya.
        $existente = CuentaPublicitaria::withoutGlobalScope('cuenta')
            ->where('plataforma', $data['plataforma'])
            ->where('id_externo', $data['id_externo'])
            ->first();
        if ($existente) {
            return response()->json(['message' => $existente->cuenta_id === CuentaActual::id()
                ? 'Esa cuenta ya está cargada.'
                : 'Esa cuenta publicitaria ya está registrada en otra cuenta del dashboard.'], 422);
        }

        try {
            $nombreRemoto = $validador->nombreRemoto($data['plataforma'], $data['id_externo']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $cuenta = CuentaPublicitaria::create([
            ...$data,
            'nombre' => $data['nombre'] ?: ($nombreRemoto ?? ($data['plataforma'] === 'meta' ? 'Meta' : 'TikTok')." {$data['id_externo']}"),
            'activa' => true,
        ]);

        return response()->json($cuenta->load('pais:id,nombre'), 201);
    }

    /**
     * Editar nombre/tipo y los dos interruptores (activa, cuenta para venta
     * real). El ID y la plataforma no se editan -- una cuenta distinta se
     * da de alta aparte.
     */
    public function updateCuentaPublicitaria(Request $request, string $pais, CuentaPublicitaria $cuentaPublicitaria): JsonResponse
    {
        abort_unless(config("paises.{$pais}"), 404, "País \"{$pais}\" no existe en config/paises.php.");
        abort_unless($request->user()->paises()->where('paises.id', $cuentaPublicitaria->pais_id)->exists(), 403);

        $data = $request->validate([
            'nombre' => ['sometimes', 'nullable', 'string', 'max:150'],
            'tipo' => ['sometimes', Rule::in(CuentaPublicitaria::TIPOS)],
            'activa' => ['sometimes', 'boolean'],
            'cuenta_en_venta_real' => ['sometimes', 'boolean'],
            // Se detecta sola al importar; esto es para corregirla a mano.
            'moneda' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
        ]);
        if (isset($data['moneda'])) {
            $data['moneda'] = strtoupper($data['moneda']);
        }

        $cuentaPublicitaria->update($data);

        return response()->json($cuentaPublicitaria->load('pais:id,nombre'));
    }

    /**
     * Tipo de cambio (2026-11-11): unidades de la moneda por 1 USD desde una
     * fecha. Misma moneda + misma fecha corrige en vez de duplicar. Aplica a
     * lo que se importe después; lo ya importado cambia al volver a importar.
     */
    public function storeTipoCambio(Request $request, string $pais): JsonResponse
    {
        abort_unless(config("paises.{$pais}"), 404, "País \"{$pais}\" no existe en config/paises.php.");

        $data = $request->validate([
            'moneda' => ['required', 'string', 'size:3', 'alpha', Rule::notIn(['USD', 'usd'])],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'por_usd' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ], [
            'moneda.not_in' => 'El dashboard ya reporta en USD: no hace falta tipo de cambio para USD.',
        ]);

        $tipo = TipoCambio::updateOrCreate(
            ['moneda' => strtoupper($data['moneda']), 'vigente_desde' => $data['vigente_desde']],
            ['por_usd' => $data['por_usd'], 'actualizado_por' => $request->user()->id],
        );

        return response()->json($tipo->only(['id', 'moneda', 'vigente_desde', 'por_usd', 'actualizado_en']));
    }

    public function destroyTipoCambio(string $pais, TipoCambio $tipoCambio): JsonResponse
    {
        abort_unless(config("paises.{$pais}"), 404, "País \"{$pais}\" no existe en config/paises.php.");
        abort_if(TipoCambio::where('moneda', $tipoCambio->moneda)->count() <= 1, 422,
            "Es el único tipo de cambio de {$tipoCambio->moneda}: sin él no se pueden importar cuentas en esa moneda.");

        $tipoCambio->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Upsert por (pais_id, plataforma) -- mismo criterio que Creativo/
     * Resultado: cargar la misma app dos veces corrige en vez de romper el
     * unique(['pais_id','plataforma']) de la tabla.
     */
    public function storeAppsflyerApp(Request $request, string $pais): JsonResponse
    {
        abort_unless(config("paises.{$pais}"), 404, "País \"{$pais}\" no existe en config/paises.php.");

        $data = $request->validate([
            // No alcanza con exists:paises,id -- tiene que ser un país que
            // el usuario realmente tenga asignado (ver comentario de
            // clase), si no cualquier EPA podía editar el App ID de un
            // país ajeno con solo cambiar el pais_id en el POST.
            'pais_id' => ['required', 'integer', Rule::in($request->user()->paises()->pluck('paises.id'))],
            'plataforma' => ['required', 'in:ios,android'],
            'app_id' => ['required', 'string', 'max:100'],
            'nombre' => ['nullable', 'string', 'max:100'],
        ]);

        $app = AppsflyerApp::updateOrCreate(
            ['pais_id' => $data['pais_id'], 'plataforma' => $data['plataforma']],
            ['app_id' => $data['app_id'], 'nombre' => $data['nombre'] ?? null],
        );

        return response()->json($app->load('pais'));
    }
}
