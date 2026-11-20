<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\CuentaPublicitaria;
use App\Models\Pais;
use App\Models\User;
use App\Services\Fuentes\ControlGasto;
use App\Services\Fuentes\HistorialCambios;
use App\Services\Fuentes\Optimizaciones;
use App\Services\Fuentes\SincronizadorOptimizaciones;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\TiktokApiClient;
use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Optimizaciones (2026-11-18): bitácora capturada en el dashboard o
 * importada de optimizaciones.2026 / ✅OPTIS.
 */
class OptimizacionesTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol = 'junior', string $codigo = 'EC', string $nombre = 'Ecuador'): User
    {
        $pais = Pais::firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre]);
        $user = User::factory()->create(['rol' => $rol, 'name' => 'Fer']);
        $user->paises()->attach($pais->id);

        return $user;
    }

    private function csv(string $nombre): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('optis.csv', file_get_contents(base_path("tests/Fixtures/{$nombre}")));
    }

    public function test_tipos_y_fechas_de_la_hoja(): void
    {
        $this->assertSame('creacion_campania', Optimizaciones::tipo('Creacion de Campana'));
        $this->assertSame('creacion_campania', Optimizaciones::tipo('Creación de Campaña'));
        $this->assertSame('apagado_grupo', Optimizaciones::tipo('Apagado de Grupo de Aununcios'));
        $this->assertSame('ajuste_optimizacion', Optimizaciones::tipo('Ajuste / Optimizacion'));
        $this->assertSame('ajuste_anuncios', Optimizaciones::tipo('Optimizacion de anuncios'));
        $this->assertSame('otro', Optimizaciones::tipo(''));
        $this->assertSame('2026-01-05', Optimizaciones::fecha('5/1/2026'));
        $this->assertSame('2026-01-09', Optimizaciones::fecha('9 ene 2026'));
        $this->assertSame('2026-07-01', Optimizaciones::fecha('01/07/2026'));
        $this->assertNull(Optimizaciones::fecha('31/2/2026'));
        $this->assertSame('Fernanda Ochoa', Optimizaciones::responsable('Fer'));
        $this->assertSame('Fernanda Ochoa', Optimizaciones::responsable('FER'));
        $this->assertSame('Fernanda Ochoa, Diana', Optimizaciones::responsable('Fer, Diana'));
        $this->assertSame('Fernanda Ochoa, LuFer', Optimizaciones::responsable('Fer y LuFer'));
        $this->assertSame('Fernanda Ochoa', Optimizaciones::responsable('Fer, Fernanda Ochoa'));
        $this->assertNull(Optimizaciones::responsable('-'));
    }

    public function test_importa_ecuador_y_peru(): void
    {
        $user = $this->usuario();
        $user->paises()->attach(Pais::firstOrCreate(['codigo' => 'PE'], ['nombre' => 'Perú'])->id);

        $this->actingAs($user)->post('/tada/pais/ecuador/optimizaciones/importar', ['archivo' => $this->csv('optimizaciones_ecuador_muestra.csv')])
            ->assertOk()->assertJson(['filas' => 5, 'desde' => '2026-01-05', 'hasta' => '2026-02-03', 'sinFecha' => 1]);
        // Reimportar no duplica.
        $this->actingAs($user)->post('/tada/pais/ecuador/optimizaciones/importar', ['archivo' => $this->csv('optimizaciones_ecuador_muestra.csv')])->assertOk();
        $this->actingAs($user)->post('/tada/pais/peru/optimizaciones/importar', ['archivo' => $this->csv('optimizaciones_peru_muestra.csv')])
            ->assertOk()->assertJson(['filas' => 3, 'desde' => '2026-01-09', 'hasta' => '2026-07-02']);

        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());
        $ec = app(Optimizaciones::class)->entre(Pais::where('codigo', 'EC')->value('id'), '2026-01-01', '2026-12-31');
        $this->assertCount(5, $ec);
        $pauta = collect($ec)->firstWhere('fecha', '2026-01-05');
        $this->assertSame(['meta', 'pauta_anuncios', 'Fernanda Ochoa, Diana', 'hoja'], [$pauta['medio'], $pauta['tipo'], $pauta['responsable'], $pauta['origen']]);
        $this->assertCount(3, $pauta['campanias']);
        $this->assertSame(['asa', 'creacion_campania'], [$ec[0]['medio'], $ec[0]['tipo']]);
        $this->assertSame('tiktok', collect($ec)->firstWhere('descripcion', 'Se apago installs IOS por ajuste de plan Enero')['medio']);

        $pe = app(Optimizaciones::class)->entre(Pais::where('codigo', 'PE')->value('id'), '2026-01-01', '2026-12-31');
        $this->assertSame('https://drive.google.com/x', collect($pe)->firstWhere('tipo', 'creacion_anuncios')['comentarios']);
        $this->assertSame([], collect($pe)->firstWhere('tipo', 'ajuste_optimizacion')['campanias']);
    }

    public function test_captura_edita_y_borra_con_historial(): void
    {
        $user = $this->usuario();
        $r = $this->actingAs($user)->post('/tada/pais/ecuador/optimizaciones', [
            'fecha' => '2026-11-17', 'medio' => 'tiktok', 'tipo' => 'ajuste_dinero',
            'campanias' => ['ECU_DTC_TAD_AON_AON_TAD_CNV_EPA_TKT-CONVERSION-AND-SMART'], 'descripcion' => 'Se sube daily de $150 a $200',
        ])->assertOk()->json();
        $this->assertSame('2026-11', $r['mes']);
        $this->assertSame(['Fernanda Ochoa', 'dashboard', $user->id], [DB::table('optimizaciones')->value('responsable'), DB::table('optimizaciones')->value('origen'), DB::table('optimizaciones')->value('usuario_id')]);

        $this->actingAs($user)->patch("/tada/pais/ecuador/optimizaciones/{$r['id']}", ['fecha' => '2026-11-17', 'tipo' => 'ajuste_dinero', 'descripcion' => 'Se sube a $250'])->assertOk();
        $this->actingAs($user)->post('/tada/pais/ecuador/optimizaciones', ['fecha' => '2026-11-17', 'tipo' => 'xx', 'descripcion' => 'a'])->assertSessionHasErrors('tipo');

        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());
        $paisId = Pais::where('codigo', 'EC')->value('id');
        // Aparece en Control de gasto (marcas del histórico).
        DB::table('medios_diario')->insert(['cuenta_id' => CuentaActual::id(), 'pais_id' => $paisId, 'fecha' => '2026-11-17', 'medio' => 'tiktok', 'medio_original' => 'tiktok', 'campania' => 'X', 'costo' => 1]);
        $cg = app(ControlGasto::class)->armar($paisId, 'ecuador', '2026-11-17', CarbonImmutable::parse('2026-11-18'));
        $this->assertSame('Se sube a $250', $cg['optimizaciones'][0]['descripcion']);

        $this->actingAs($user)->delete("/tada/pais/ecuador/optimizaciones/{$r['id']}")->assertOk();
        $this->assertSame(0, DB::table('optimizaciones')->count());
        $textos = array_column(app(HistorialCambios::class)->listar('ecuador', 'EC', ['area' => 'optimizaciones'])['eventos'], 'texto');
        $this->assertSame([
            'Borró la optimización (Ajuste de dinero) del 17 nov 2026.',
            'Editó la optimización (Ajuste de dinero) del 17 nov 2026.',
            'Registró una optimización (Ajuste de dinero) del 17 nov 2026 en ECU_DTC_TAD_AON_AON_TAD_CNV_EPA_TKT-CONVERSION-AND-SMART.',
        ], $textos);
    }

    public function test_cliente_no_entra(): void
    {
        $this->actingAs($this->usuario('cliente'))->get('/tada/pais/ecuador/optimizaciones')->assertForbidden();
    }

    private function cuentaPublicitaria(string $plataforma, string $id): CuentaPublicitaria
    {
        $pais = Pais::firstOrCreate(['codigo' => 'EC'], ['nombre' => 'Ecuador']);
        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());

        return CuentaPublicitaria::create(['pais_id' => $pais->id, 'plataforma' => $plataforma, 'id_externo' => $id, 'nombre' => "EC {$plataforma}", 'activa' => true]);
    }

    public function test_sincroniza_meta_sin_duplicar_y_respeta_lo_de_la_hoja(): void
    {
        $this->cuentaPublicitaria('meta', '111');
        $pais = Pais::where('codigo', 'EC')->first();
        // Ya importado de la hoja: el mismo ajuste no se vuelve a escribir.
        DB::table('optimizaciones')->insert(['cuenta_id' => CuentaActual::id(), 'pais_id' => $pais->id, 'fecha' => '2026-11-16', 'medio' => 'meta',
            'campanias' => json_encode(['CAMP_HOJA']), 'tipo' => 'ajuste_dinero', 'descripcion' => 'x', 'origen' => 'hoja', 'creado_en' => now(), 'actualizado_en' => now()]);

        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            ['event_type' => 'update_ad_set_budget', 'event_time' => '2026-11-17T15:00:00+0000', 'object_id' => '1', 'object_name' => 'CAMP_A', 'actor_name' => 'Fer', 'extra_data' => json_encode(['old_value' => 7000, 'new_value' => 8000])],
            ['event_type' => 'update_ad_set_budget', 'event_time' => '2026-11-17T18:00:00+0000', 'object_id' => '1', 'object_name' => 'CAMP_A', 'actor_name' => 'Fer', 'extra_data' => json_encode(['old_value' => 8000, 'new_value' => 6000])],
            ['event_type' => 'update_ad_run_status', 'event_time' => '2026-11-17T03:00:00+0000', 'object_id' => '2', 'object_name' => 'AD_B', 'actor_name' => 'Diana', 'extra_data' => json_encode(['old_value' => 'Procesamiento pendiente', 'new_value' => 'Inactivo'])],
            // Ruido: pasos intermedios y lo que hace Meta solo tras la revisión.
            ['event_type' => 'update_ad_run_status', 'event_time' => '2026-11-17T04:00:00+0000', 'object_id' => '4', 'object_name' => 'AD_C', 'actor_name' => 'Diana', 'extra_data' => json_encode(['old_value' => 'Procesamiento pendiente', 'new_value' => 'Revisión pendiente'])],
            ['event_type' => 'update_ad_run_status', 'event_time' => '2026-11-17T05:00:00+0000', 'object_id' => '4', 'object_name' => 'AD_C', 'actor_name' => 'Meta', 'extra_data' => json_encode(['old_value' => 'Revisión pendiente', 'new_value' => 'Activo'])],
            ['event_type' => 'update_ad_run_status', 'event_time' => '2026-11-17T06:00:00+0000', 'object_id' => '5', 'object_name' => 'AD_D', 'actor_name' => 'Diana', 'extra_data' => json_encode(['old_value' => 'Inactivo', 'new_value' => 'Eliminado'])],
            ['event_type' => 'update_ad_set_budget', 'event_time' => '2026-11-16T15:00:00+0000', 'object_id' => '3', 'object_name' => 'CAMP_HOJA', 'extra_data' => '{}'],
            ['event_type' => 'ad_review_approved', 'event_time' => '2026-11-17T15:00:00+0000', 'object_name' => 'X'],
        ]])]);

        $sync = new SincronizadorOptimizaciones(new MetaApiClient('t'));
        $this->assertSame(3, $sync->sincronizar('ecuador')['meta']);
        $this->assertSame(0, $sync->sincronizar('ecuador')['meta']); // segunda corrida: nada nuevo

        $filas = DB::table('optimizaciones')->where('origen', 'meta')->orderBy('id')->get();
        $this->assertSame(['Se ajusta ppto de $70 a $80 (+14%)', 'Se ajusta ppto de $80 a $60 (-25%)'], $filas->where('tipo', 'ajuste_dinero')->pluck('descripcion')->values()->all());
        $this->assertSame('Fernanda Ochoa', $filas->first()->responsable);
        // 03:00 UTC del 17 = 16 de noviembre en Ecuador; Inactivo = apagado.
        $apagado = $filas->firstWhere('tipo', 'apagado_anuncio');
        $this->assertSame('2026-11-16', substr((string) $apagado->fecha, 0, 10));
        $this->assertSame('Diana', $apagado->responsable);
    }

    public function test_tiktok_compara_contra_la_foto(): void
    {
        $this->cuentaPublicitaria('tiktok', '222');
        $campanias = [['campaign_id' => 'c1', 'campaign_name' => 'CAMP_T', 'operation_status' => 'ENABLE', 'budget' => 100, 'modify_time' => '2026-11-10 10:00:00']];
        $grupos = [['adgroup_id' => 'g1', 'adgroup_name' => 'GRUPO_T', 'operation_status' => 'ENABLE', 'budget' => 70, 'modify_time' => '2026-11-10 10:00:00']];
        $anuncios = [];
        Http::fake(function ($req) use (&$campanias, &$grupos, &$anuncios) {
            $lista = str_contains($req->url(), '/campaign/get/') ? $campanias : (str_contains($req->url(), '/adgroup/get/') ? $grupos : $anuncios);

            return Http::response(['code' => 0, 'data' => ['list' => $lista, 'page_info' => ['total_page' => 1]]]);
        });
        $sync = new SincronizadorOptimizaciones(null, new TiktokApiClient('t'));

        // Primera corrida: solo la foto.
        $this->assertSame(0, $sync->sincronizar('ecuador')['tiktok']);
        $this->assertSame(2, DB::table('plataforma_snapshot')->count());

        $grupos[0]['budget'] = 80;
        $grupos[0]['modify_time'] = '2026-11-17 09:00:00';
        $campanias[0]['operation_status'] = 'DISABLE';
        $anuncios[] = ['ad_id' => 'a1', 'ad_name' => 'AD_NUEVO', 'operation_status' => 'ENABLE', 'create_time' => '2026-11-17 08:00:00'];
        $this->assertSame(3, $sync->sincronizar('ecuador')['tiktok']);
        $this->assertSame(0, $sync->sincronizar('ecuador')['tiktok']);

        $filas = DB::table('optimizaciones')->where('origen', 'tiktok')->get()->keyBy('tipo');
        $this->assertSame('Se ajusta ppto de $70 a $80 (+14%)', $filas['ajuste_dinero']->descripcion);
        $this->assertSame('2026-11-17', substr((string) $filas['ajuste_dinero']->fecha, 0, 10));
        $this->assertArrayHasKey('apagado_campania', $filas->all());
        $this->assertSame('Se pautó anuncio: AD_NUEVO', $filas['pauta_anuncios']->descripcion);
        $this->assertSame('tiktok', $filas['pauta_anuncios']->medio);
    }

    public function test_meta_guarda_campania_madre_y_daily_de_la_campania(): void
    {
        $this->cuentaPublicitaria('meta', '111');
        Http::fake(function ($req) {
            $ruta = parse_url($req->url(), PHP_URL_PATH);
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            if (str_ends_with($ruta, '/activities')) {
                return Http::response(['data' => [
                    // Ad set sin CBO: 70 → 120; su hermano activo tiene 50 → la campaña queda en 170/día.
                    ['event_type' => 'update_ad_set_budget', 'event_time' => '2026-11-17T15:00:00+0000', 'object_id' => 'as1', 'object_name' => 'ADSET_A', 'actor_name' => 'Ana', 'extra_data' => json_encode(['old_value' => ['old_value' => 7000, 'type' => 'daily_budget'], 'new_value' => ['new_value' => 12000, 'type' => 'daily_budget']])],
                    // Campaña (CBO): no tiene campo campaign → se toma como la campaña misma.
                    ['event_type' => 'update_campaign_budget', 'event_time' => '2026-11-17T16:00:00+0000', 'object_id' => 'c2', 'object_name' => 'CAMP_CBO', 'actor_name' => 'Ana', 'extra_data' => json_encode(['old_value' => 10000, 'new_value' => 20000])],
                    // Como lo manda Meta de verdad: nivel CAMPAIGN_GROUP y "por día".
                    ['event_type' => 'update_campaign_budget', 'event_time' => '2026-11-17T17:00:00+0000', 'object_id' => 'c3', 'object_type' => 'CAMPAIGN_GROUP', 'object_name' => 'CAMP_REAL', 'actor_name' => 'Ana', 'extra_data' => json_encode(['old_value' => ['type' => 'payment_amount', 'old_value' => 7000, 'additional_value' => 'por día'], 'new_value' => ['type' => 'payment_amount', 'new_value' => 3000, 'additional_value' => 'por día']])],
                ]]);
            }
            if (str_ends_with($ruta, '/as1')) {
                return Http::response(['name' => 'ADSET_A', 'daily_budget' => '12000', 'campaign' => ['id' => 'c1', 'name' => 'CAMP_A']]);
            }
            if (str_ends_with($ruta, '/c1/adsets')) {
                return Http::response(['data' => [
                    ['id' => 'as1', 'daily_budget' => '12000', 'effective_status' => 'ACTIVE'],
                    ['id' => 'as2', 'daily_budget' => '5000', 'effective_status' => 'ACTIVE'],
                    ['id' => 'as3', 'daily_budget' => '9000', 'effective_status' => 'PAUSED'],
                ]]);
            }
            if (str_ends_with($ruta, '/c2')) {
                return str_contains($q['fields'] ?? '', 'campaign{')
                    ? Http::response(['error' => ['message' => 'Tried accessing nonexisting field (campaign)']], 400)
                    : Http::response(['name' => 'CAMP_CBO', 'daily_budget' => '20000']);
            }

            if (str_ends_with($ruta, '/c3')) {
                // Directo como campaña, sin probar primero como ad set.
                return str_contains($q['fields'] ?? '', 'campaign{') ? Http::response(['error' => ['message' => 'no']], 500) : Http::response(['name' => 'CAMP_REAL', 'daily_budget' => '3000']);
            }

            return Http::response([], 404);
        });

        (new SincronizadorOptimizaciones(new MetaApiClient('t')))->sincronizar('ecuador');
        $filas = DB::table('optimizaciones')->where('origen', 'meta')->get()->keyBy(fn ($f) => json_decode($f->campanias)[0]);

        $a = $filas['ADSET_A'];
        $this->assertSame(['CAMP_A', 'c1', 1, 70.0, 120.0, 170.0], [$a->campania_padre, $a->campania_padre_id, (int) $a->presupuesto_diario, (float) $a->presupuesto_anterior, (float) $a->presupuesto_nuevo, (float) $a->presupuesto_campania]);
        $c = $filas['CAMP_CBO'];
        $this->assertSame(['CAMP_CBO', 'c2', 1, 200.0], [$c->campania_padre, $c->campania_padre_id, (int) $c->presupuesto_diario, (float) $c->presupuesto_campania]);
        $r = $filas['CAMP_REAL'];
        $this->assertSame(['CAMP_REAL', 'c3', 1, 70.0, 30.0, 30.0], [$r->campania_padre, $r->campania_padre_id, (int) $r->presupuesto_diario, (float) $r->presupuesto_anterior, (float) $r->presupuesto_nuevo, (float) $r->presupuesto_campania]);
    }

    public function test_tiktok_daily_de_la_campania_suma_sus_grupos(): void
    {
        $actual = [
            'campaign:c1' => ['tipo' => 'campaign', 'nombre' => 'CAMP_T', 'status' => 'ENABLE', 'budget' => null, 'campaign_id' => 'c1', 'diario' => null],
            'adgroup:g1' => ['tipo' => 'adgroup', 'nombre' => 'G1', 'status' => 'ENABLE', 'budget' => 80.0, 'campaign_id' => 'c1', 'diario' => true],
            'adgroup:g2' => ['tipo' => 'adgroup', 'nombre' => 'G2', 'status' => 'ENABLE', 'budget' => 50.0, 'campaign_id' => 'c1', 'diario' => true],
            'adgroup:g3' => ['tipo' => 'adgroup', 'nombre' => 'G3', 'status' => 'DISABLE', 'budget' => 90.0, 'campaign_id' => 'c1', 'diario' => true],
            'adgroup:g4' => ['tipo' => 'adgroup', 'nombre' => 'G4', 'status' => 'ENABLE', 'budget' => 999.0, 'campaign_id' => 'c9', 'diario' => true],
        ];
        $d = SincronizadorOptimizaciones::detallePresupuestoTiktok($actual, $actual['adgroup:g1'], 70);

        $this->assertSame(['CAMP_T', 'c1', 70.0, 80.0, true, 130.0], [$d['campania_padre'], $d['campania_padre_id'], $d['presupuesto_anterior'], $d['presupuesto_nuevo'], $d['presupuesto_diario'], $d['presupuesto_campania']]);
    }
}
