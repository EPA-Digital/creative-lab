<?php

namespace Tests\Feature\MultiCuenta;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Pais;
use App\Models\User;
use App\Services\Ingesta\CruceCostosAppsFlyer;
use App\Services\Ingesta\FormulaSheets;
use App\Services\Ingesta\Nomenclatura;
use App\Services\Ingesta\ReglasNomenclatura;
use App\Support\CuentaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Clasificación guiada por cuenta: cada equipo enseña sus siglas de tipo de
 * campaña, sus etapas de funnel (con equivalente) y ejemplos; la IA propone
 * reglas (siglas o regex) y se aplican sin IA. Nombres reales de Openbank
 * del cuestionario de alta (2026-11-04).
 */
class NomenclaturaTest extends TestCase
{
    use RefreshDatabase;

    private const ANUNCIOS = [
        'fb_obmx_lead_do_auct_app_event_android_lal_tdd_all_1-3_shaula-ponce_28-sep-26_influencer_madrid',
        'fb_obmx_lead_do_auct_app_event_android_lal_tdd_all_1-3_21-oct-26_marlene-marquez_influencer_madrid_v1',
        'fb_obmx_lead_do_auct_app_event_ios-14_tdd_abelardo-bobadilla_13-oct-26_influencer_madrid',
    ];

    private const CAMPANIA_BRANDING = 'fb_obmx_brn_see_auct_reach_all';

    private const REGLAS_OPENBANK = [
        'version' => 2,
        'identificador' => ['siglas' => ['obmx']],
        'tipos_campania' => [
            ['nombre' => 'Performance', 'eje' => 'performance', 'siglas' => ['lead']],
            ['nombre' => 'Branding', 'eje' => 'branding', 'siglas' => ['brn']],
        ],
        'funnel' => [
            ['nombre' => 'See', 'equivalente' => 'AWA', 'siglas' => ['see']],
            ['nombre' => 'Do', 'equivalente' => 'CNV', 'siglas' => ['do']],
        ],
        'campos' => ['plataforma' => [
            ['siglas' => ['fb'], 'valor' => 'meta'],
            ['siglas' => ['tt'], 'valor' => 'tiktok'],
            ['siglas' => ['aw'], 'valor' => 'google_ads'],
        ]],
        'dimensiones' => [
            ['nombre' => 'Producto', 'reglas' => [['siglas' => ['tdd'], 'valor' => 'TDD'], ['siglas' => ['tdc'], 'valor' => 'TDC']]],
            ['nombre' => 'Sistema operativo', 'reglas' => [['patron' => 'ios(-\d+)?', 'valor' => 'iOS'], ['siglas' => ['android'], 'valor' => 'Android']]],
        ],
        'arte' => ['regex' => '_((?:[a-z]+-[a-z]+_)?\d{1,2}-[a-z]{3}-\d{2}_.+?)(?:_v\d+)?$'],
    ];

    /** Lo que llena el equipo en el formulario (pasos 1 a 4). */
    private const CUESTIONARIO = [
        'tipos' => [
            ['nombre' => 'Performance', 'eje' => 'performance', 'siglas' => 'lead', 'ejemplos' => []],
            ['nombre' => 'Branding', 'eje' => 'branding', 'siglas' => 'brn', 'ejemplos' => []],
        ],
        'etapas' => [
            ['nombre' => 'See', 'equivalente' => 'AWA', 'siglas' => 'see', 'ejemplos' => ['fb_obmx_brn_see_auct_reach_all', 'tt_obmx_brn_see_reach_all']],
            ['nombre' => 'Do', 'equivalente' => 'CNV', 'siglas' => 'do', 'ejemplos' => ['aw_obmx_lead_do_uac_android_install_tdd', 'fb_obmx_lead_do_auct_app_event_android_lal_tdd']],
        ],
        'campanias' => [],
        'conjuntos' => ['fb_obmx_lead_do_auct_app_event_android_lal_tdd_all_1-3'],
        'anuncios' => self::ANUNCIOS,
        'notas' => 'fb / obmx / lead / do = medio / cliente / línea / funnel',
    ];

    private Cuenta $openbank;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->openbank = Cuenta::create([
            'cliente_id' => Cliente::create(['nombre' => 'Openbank'])->id,
            'nombre' => 'Openbank', 'slug' => 'openbank',
            'servicios' => ['App'], 'objetivos' => ['Instalaciones'], 'medios' => ['meta', 'tiktok'],
        ]);
    }

    private function reglas(array $cambios = []): array
    {
        return ReglasNomenclatura::normalizar([...self::REGLAS_OPENBANK, ...$cambios]);
    }

    public function test_lee_tipo_etapa_propia_y_dimensiones_de_los_nombres_reales(): void
    {
        $r = ReglasNomenclatura::parsear($this->reglas(), null, self::ANUNCIOS[1]);

        $this->assertSame(['meta', 'CNV', 'Do', 'nombre'], [$r['plataforma'], $r['funnel'], $r['funnelPropio'], $r['fuenteFunnel']]);
        $this->assertSame(['performance', 'Performance'], [$r['tipoCampania'], $r['tipoCampaniaNombre']]);
        $this->assertSame(['producto' => 'TDD', 'sistema_operativo' => 'Android'], $r['dimensiones']);
        $this->assertSame('21-OCT-26_MARLENE-MARQUEZ_INFLUENCER_MADRID', $r['arte']);
        $this->assertNull($r['tipoCuenta']);
        $this->assertSame('siglas do', $r['reglasAplicadas']['funnel']);

        $branding = ReglasNomenclatura::parsear($this->reglas(), self::CAMPANIA_BRANDING, null);
        $this->assertSame(['AWA', 'See', 'branding'], [$branding['funnel'], $branding['funnelPropio'], $branding['tipoCampania']]);
        $this->assertSame('iOS', ReglasNomenclatura::parsear($this->reglas(), null, self::ANUNCIOS[2])['dimensiones']['sistema_operativo']);
    }

    public function test_las_siglas_son_palabra_completa(): void
    {
        // "do" no coincide dentro de "android".
        $r = ReglasNomenclatura::parsear($this->reglas(), 'fb_obmx_lead_auct_app_android', null);

        $this->assertNull($r['funnel']);
    }

    public function test_sin_etapa_en_el_nombre_usa_el_objetivo_de_la_campania(): void
    {
        $r = ReglasNomenclatura::parsear($this->reglas(), 'fb_obmx_lead_auct_app', null, null, 'OUTCOME_AWARENESS');
        $this->assertSame(['AWA', 'See', 'objetivo'], [$r['funnel'], $r['funnelPropio'], $r['fuenteFunnel']]);

        // El nombre siempre gana al objetivo.
        $this->assertSame('CNV', ReglasNomenclatura::parsear($this->reglas(), 'fb_obmx_lead_do', null, null, 'OUTCOME_AWARENESS')['funnel']);

        // Apagado, o con un objetivo que no se conoce: sin clasificar.
        $this->assertNull(ReglasNomenclatura::parsear($this->reglas(['usar_objetivo_plataforma' => false]), 'fb_obmx_lead_auct', null, null, 'OUTCOME_AWARENESS')['funnel']);
        $this->assertNull(ReglasNomenclatura::parsear($this->reglas(), 'fb_obmx_lead_auct', null, null, 'ALGO_NUEVO')['funnel']);
    }

    public function test_busca_en_campania_luego_conjunto_luego_anuncio(): void
    {
        $r = ReglasNomenclatura::parsear($this->reglas(), 'fb_obmx_lead_do', 'fb_obmx_tdd_ad', 'fb_obmx_tdc_conjunto');

        // El conjunto (tdc) gana al anuncio (tdd).
        $this->assertSame('TDC', $r['dimensiones']['producto']);
    }

    public function test_un_nombre_sin_el_identificador_no_es_de_la_cuenta(): void
    {
        $r = ReglasNomenclatura::parsear($this->reglas(), 'fb_chedraui_branding_see', 'fb_chedraui_branding_see_video');

        $this->assertFalse($r['esDeLaCuenta']);
        $this->assertNull($r['plataforma']);
    }

    public function test_las_reglas_de_la_version_anterior_se_convierten(): void
    {
        $v1 = [
            'separador' => '_',
            'identificador' => ['segmento' => 'obmx', 'posicion' => 1],
            'campos' => [
                'plataforma' => ['posicion' => 0, 'valores' => ['fb' => 'meta']],
                'funnel' => ['posicion' => 3, 'valores' => ['see' => 'AWA', 'do' => 'CNV']],
            ],
            'dimensiones' => [['nombre' => 'Producto', 'posicion' => null, 'valores' => ['tdd' => 'TDD']]],
        ];

        $reglas = ReglasNomenclatura::normalizar($v1);
        $r = ReglasNomenclatura::parsear($reglas, null, self::ANUNCIOS[0]);

        $this->assertSame(2, $reglas['version']);
        $this->assertSame(['meta', 'CNV', 'Do', 'TDD'], [$r['plataforma'], $r['funnel'], $r['funnelPropio'], $r['dimensiones']['producto']]);
    }

    public function test_tolera_campos_mal_formados_de_la_ia(): void
    {
        $reglas = $this->reglas(['campos' => ['plataforma' => ['posicion' => 0, 'valores' => ['fb' => 'meta']]], 'funnel' => ['texto suelto', ...self::REGLAS_OPENBANK['funnel']]]);

        $this->assertSame('meta', ReglasNomenclatura::parsear($reglas, null, self::ANUNCIOS[0])['plataforma']);
        $this->assertCount(2, $reglas['funnel']);
    }

    public function test_errores_en_espanol(): void
    {
        try {
            $this->reglas(['funnel' => [['nombre' => 'Retargeting', 'equivalente' => 'RTG', 'siglas' => ['rtg']]]]);
            $this->fail('Debió rechazar el equivalente.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Elige a qué equivale la etapa Retargeting', $e->getMessage());
        }

        $this->expectExceptionMessage('no es una expresión regular válida');
        $this->reglas(['tipos_campania' => [['nombre' => 'Performance', 'eje' => 'performance', 'patron' => '(sin cerrar']]]);
    }

    public function test_formula_de_sheets_a_reglas(): void
    {
        $reglas = FormulaSheets::convertir('=IFS(REGEXMATCH(A2,"(?i)_see_"),"See",REGEXMATCH(LOWER(A2),"_do_|_lead_"),"Do",TRUE,"Sin clasificar")');

        $this->assertSame([['patron' => '_see_', 'valor' => 'See'], ['patron' => '_do_|_lead_', 'valor' => 'Do']], $reglas);
        $this->assertSame([['patron' => 'brn', 'valor' => 'Branding']], FormulaSheets::convertir('=IF(REGEXMATCH(B2,"brn"),"Branding","Performance")'));

        $this->expectExceptionMessage('No encontré ningún REGEXMATCH');
        FormulaSheets::convertir('=SI(A2="x";"y")');
    }

    public function test_el_pipeline_usa_conjunto_y_guarda_la_clasificacion_propia_y_tada_no_cambia(): void
    {
        $this->openbank->update(['nomenclatura' => self::REGLAS_OPENBANK]);
        $ad = ['adId' => '1', 'campaign' => 'fb_obmx_lead_do_auct', 'adRaw' => self::ANUNCIOS[0], 'conjunto' => 'fb_obmx_tdc', 'serie' => []];
        $ajeno = ['adId' => '2', 'campaign' => 'fb_otro_lead_do', 'adRaw' => 'fb_otro_lead_do_algo', 'serie' => []];

        $r = CuentaActual::usar($this->openbank, fn () => CruceCostosAppsFlyer::clasificarPorPlataforma([$ad, $ajeno]));

        $this->assertCount(1, $r['meta']);
        $this->assertCount(1, $r['excluidos']);
        $card = $r['meta'][0];
        $this->assertSame(['CNV', 'Do', 'performance', 'Performance'], [$card['etapaFunnel'], $card['funnelPropio'], $card['tipoCampania'], $card['tipoCampaniaNombre']]);
        $this->assertSame('TDC', $card['dimensiones']['producto']);

        $tada = CuentaActual::usar(Cuenta::tada(), fn () => Nomenclatura::parsear($ad['campaign'], $ad['adRaw'], 'x', 'OUTCOME_AWARENESS'));
        $this->assertNotSame('NOMENCLATURA_CUENTA', $tada['patron']);
        $this->assertArrayNotHasKey('funnelPropio', $tada);
    }

    public function test_la_ia_propone_respetando_el_cuestionario_y_la_vista_previa_cuenta_aciertos(): void
    {
        // La IA "olvida" los tipos de campaña: los del cuestionario se ponen igual.
        $propuesta = self::REGLAS_OPENBANK;
        unset($propuesta['tipos_campania']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => "Listo:\n```json\n".json_encode($propuesta)."\n```"]],
        ])]);

        $r = $this->actingAs($this->epa())->postJson('/cuentas/nomenclatura/proponer', ['nombre' => 'Openbank', ...self::CUESTIONARIO])
            ->assertOk()
            ->assertJsonPath('reglas.tipos_campania.0.siglas', ['lead'])
            ->assertJsonPath('reglas.tipos_campania.1.nombre', 'Branding')
            ->assertJsonPath('resumen.etapas.0', ['nombre' => 'See', 'aciertos' => 2, 'total' => 2])
            ->assertJsonPath('resumen.etapas.1', ['nombre' => 'Do', 'aciertos' => 2, 'total' => 2]);

        $anuncio = collect($r->json('vista_previa'))->firstWhere('nombre', self::ANUNCIOS[0]);
        $this->assertSame('SHAULA-PONCE_28-SEP-26_INFLUENCER_MADRID', $anuncio['arte']);

        Http::assertSent(function ($req) {
            $prompt = $req['messages'][0]['content'][0]['text'] ?? '';

            return str_contains($prompt, 'tt_obmx_brn_see_reach_all') // ejemplo etiquetado de See
                && str_contains($prompt, 'See (equivale a AWA; siglas: see)')
                && str_contains($prompt, 'medio / cliente / línea / funnel');
        });
    }

    public function test_probar_solo_con_las_siglas_del_cuestionario_no_llama_a_la_ia(): void
    {
        Http::fake();

        $this->actingAs($this->epa())->postJson('/cuentas/nomenclatura/probar', self::CUESTIONARIO)
            ->assertOk()
            ->assertJsonPath('reglas.funnel.1.nombre', 'Do')
            ->assertJsonPath('resumen.etapas.1.aciertos', 2)
            ->assertJsonPath('resumen.tipos.0.total', 0);

        Http::assertNothingSent();
    }

    public function test_si_la_ia_devuelve_basura_avisa_sin_romper(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'No sé']]])]);

        $this->actingAs($this->epa())->postJson('/cuentas/nomenclatura/proponer', ['anuncios' => self::ANUNCIOS])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La IA no devolvió reglas legibles. Intenta de nuevo o agrega más ejemplos.');
    }

    public function test_sin_ejemplos_suficientes_pide_mas(): void
    {
        $this->actingAs($this->epa())->postJson('/cuentas/nomenclatura/probar', ['anuncios' => ['uno']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['anuncios']);
    }

    public function test_formula_por_endpoint(): void
    {
        $this->actingAs($this->epa())->postJson('/cuentas/nomenclatura/desde-formula', ['formula' => '=IFS(REGEXMATCH(A2,"see"),"See")'])
            ->assertOk()->assertJsonPath('reglas.0', ['patron' => 'see', 'valor' => 'See']);

        $this->actingAs($this->epa())->postJson('/cuentas/nomenclatura/desde-formula', ['formula' => '=A2'])
            ->assertStatus(422)->assertJsonValidationErrors(['formula']);
    }

    public function test_se_guarda_con_la_cuenta_y_la_pagina_muestra_sus_etapas_y_tipos(): void
    {
        $user = $this->epa();
        $user->cuentas()->attach($this->openbank->id);
        $this->openbank->paises()->attach(Pais::where('codigo', 'MX')->value('id'));

        $this->actingAs($user)->putJson('/cuentas/openbank', [
            'nombre' => 'Openbank', 'cliente' => 'Openbank', 'color' => '#ff8f84',
            'servicios' => ['App'], 'objetivos' => ['Instalaciones'], 'medios' => ['meta'],
            'venta_real' => 'no', 'multi_pais' => false, 'paises' => ['MX'],
            'nomenclatura' => self::REGLAS_OPENBANK,
            'nomenclatura_ejemplos' => self::CUESTIONARIO,
        ])->assertOk();

        $this->openbank->refresh();
        $this->assertSame(2, $this->openbank->nomenclatura['version']);
        $this->assertSame('See', $this->openbank->nomenclatura_ejemplos['etapas'][0]['nombre']);

        $this->actingAs($user)->get('/openbank/pais/mexico/analisis')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cuentaActual.etapas.AWA', 'See')
                ->where('cuentaActual.etapas.CNV', 'Do')
                ->where('cuentaActual.tiposCampania.1', ['nombre' => 'Branding', 'eje' => 'branding']));
    }

    public function test_tada_siempre_usa_su_clasificador_aunque_le_manden_reglas(): void
    {
        Cuenta::tada()->update(['nomenclatura' => self::REGLAS_OPENBANK]);

        $r = CuentaActual::usar(Cuenta::tada(), fn () => Nomenclatura::parsear('ECU_BRD_TAD_AON_FB-CONVERSION', 'VID-01JUL-31JUL-PILSENER'));
        $this->assertNotSame('NOMENCLATURA_CUENTA', $r['patron']);
        $this->assertSame('BRD', $r['tipoCuenta']);

        Cuenta::tada()->update(['nomenclatura' => null]);
        $this->actingAs($this->epa())->putJson('/cuentas/tada', [
            'nombre' => 'TaDa', 'cliente' => 'AB InBev', 'color' => '#9184d9',
            'servicios' => ['App'], 'objetivos' => ['Ventas'], 'medios' => ['meta'],
            'venta_real' => 'manual', 'multi_pais' => false, 'paises' => ['MX'],
            'nomenclatura' => self::REGLAS_OPENBANK,
        ])->assertOk();
        $this->assertNull(Cuenta::tada()->nomenclatura);
    }

    private function epa(): User
    {
        $user = User::factory()->create(['rol' => 'junior']);
        $user->paises()->attach(Pais::firstOrCreate(['codigo' => 'MX'], ['nombre' => 'México'])->id);

        return $user;
    }
}
