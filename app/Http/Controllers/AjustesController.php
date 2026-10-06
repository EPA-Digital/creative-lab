<?php

namespace App\Http\Controllers;

use App\Models\AppsflyerApp;
use App\Models\CuentaPublicitaria;
use App\Models\Importacion;
use App\Models\Pais;
use App\Services\Ingesta\ClasificadorNombres;
use App\Services\Ingesta\ValidadorCuentaPublicitaria;
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
            'cuenta_id' => ['required', 'string', 'max:50', 'regex:/^(act_)?\d+$/i'],
            'nombre' => ['nullable', 'string', 'max:150'],
            'tipo' => ['required', Rule::in(CuentaPublicitaria::TIPOS)],
            'cuenta_en_venta_real' => ['required', 'boolean'],
        ], [
            'cuenta_id.regex' => 'El ID de cuenta debe ser solo números (en Meta puede llevar "act_" adelante).',
        ]);

        $data['cuenta_id'] = ValidadorCuentaPublicitaria::normalizarId($data['cuenta_id']);
        if (CuentaPublicitaria::where('plataforma', $data['plataforma'])->where('cuenta_id', $data['cuenta_id'])->exists()) {
            return response()->json(['message' => 'Esa cuenta ya está cargada.'], 422);
        }

        try {
            $nombreRemoto = $validador->nombreRemoto($data['plataforma'], $data['cuenta_id']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $cuenta = CuentaPublicitaria::create([
            ...$data,
            'nombre' => $data['nombre'] ?: ($nombreRemoto ?? ($data['plataforma'] === 'meta' ? 'Meta' : 'TikTok')." {$data['cuenta_id']}"),
            'activa' => true,
        ]);

        return response()->json($cuenta->load('pais:id,nombre'), 201);
    }

    /**
     * Editar nombre/tipo y los dos interruptores (activa, cuenta para venta
     * real). El ID y la plataforma no se editan -- una cuenta distinta se
     * da de alta aparte.
     */
    public function updateCuentaPublicitaria(Request $request, string $pais, CuentaPublicitaria $cuenta): JsonResponse
    {
        abort_unless(config("paises.{$pais}"), 404, "País \"{$pais}\" no existe en config/paises.php.");
        abort_unless($request->user()->paises()->where('paises.id', $cuenta->pais_id)->exists(), 403);

        $data = $request->validate([
            'nombre' => ['sometimes', 'nullable', 'string', 'max:150'],
            'tipo' => ['sometimes', Rule::in(CuentaPublicitaria::TIPOS)],
            'activa' => ['sometimes', 'boolean'],
            'cuenta_en_venta_real' => ['sometimes', 'boolean'],
        ]);

        $cuenta->update($data);

        return response()->json($cuenta->load('pais:id,nombre'));
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
