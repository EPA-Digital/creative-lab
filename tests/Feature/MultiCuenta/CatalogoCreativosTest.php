<?php

namespace Tests\Feature\MultiCuenta;

use App\Models\Cliente;
use App\Models\Creativo;
use App\Models\Cuenta;
use App\Models\Pais;
use App\Models\Resultado;
use App\Models\User;
use App\Services\Ingesta\CruceCostosAppsFlyer;
use App\Services\Ingesta\ReglasNomenclatura;
use App\Support\CuentaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Catálogo de creativos por cuenta (2026-11-05): el "Catálogo Creativos" de
 * Openbank (patrón → creativo) pegado tal cual, reglas por post de TikTok
 * para Spark Ads, y la cola "Sin creativo" que escribe en ese catálogo.
 * Nombres y patrones reales de su hoja "Openbank || Reporte anuncios || 2026".
 */
class CatalogoCreativosTest extends TestCase
{
    use RefreshDatabase;

    private const REGLAS = [
        'version' => 2,
        'identificador' => ['siglas' => ['obmx', 'openbank']],
        'campos' => ['plataforma' => [['siglas' => ['fb'], 'valor' => 'meta'], ['siglas' => ['tk'], 'valor' => 'tiktok']]],
        'funnel' => [['nombre' => 'Do', 'equivalente' => 'CNV', 'siglas' => ['do']]],
        'arte' => [
            'catalogo' => [
                ['patron' => '_outfit-match|match con tu tarjeta', 'valor' => 'Outfit Match'],
                ['patron' => 'post_asmr|_asmr_|El ASMR de los adultos chiquitos', 'valor' => 'ASMR'],
            ],
            'regex' => '_((?:[a-z]+-[a-z]+_)?\d{1,2}-[a-z]{3}-\d{2}_.+?)(?:_v\d+)?$',
        ],
    ];

    private Cuenta $openbank;

    private Pais $mx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mx = Pais::create(['codigo' => 'MX', 'nombre' => 'México']);
        $this->openbank = Cuenta::create([
            'cliente_id' => Cliente::create(['nombre' => 'Openbank'])->id,
            'nombre' => 'Openbank', 'slug' => 'openbank',
            'servicios' => ['App'], 'objetivos' => ['Instalaciones'], 'medios' => ['meta', 'tiktok'],
            'nomenclatura' => ReglasNomenclatura::normalizar(self::REGLAS),
        ]);
        $this->openbank->paises()->attach($this->mx->id);
    }

    private function reglas(): array
    {
        return $this->openbank->fresh()->nomenclatura;
    }

    public function test_el_catalogo_da_el_nombre_del_creativo_tal_cual(): void
    {
        $r = ReglasNomenclatura::parsear($this->reglas(), 'fb_obmx_lead_do', 'fb_obmx_lead_do_auct_app_event_android_rmk_tdd_120d_outfit-match_19-may-26_post');
        $this->assertSame('Outfit Match', $r['arte']);
        $this->assertStringStartsWith('catálogo:', $r['reglasAplicadas']['arte']);

        // Spark Ad: el nombre es el texto del post, y el catálogo también lo atrapa.
        $spark = ReglasNomenclatura::parsear($this->reglas(), 'tk_obmx_brn_do_auct', 'El ASMR de los adultos chiquitos 🫧 #openbank');
        $this->assertSame('ASMR', $spark['arte']);

        // Sin patrón del catálogo: la regex de respaldo.
        $this->assertSame('SHAULA-PONCE_28-SEP-26_INFLUENCER_MADRID', ReglasNomenclatura::parsear($this->reglas(), null, 'fb_obmx_lead_do_all_1-3_shaula-ponce_28-sep-26_influencer_madrid')['arte']);
    }

    public function test_una_regla_por_post_gana_al_patron(): void
    {
        $reglas = ReglasNomenclatura::normalizar([...self::REGLAS, 'arte' => [...self::REGLAS['arte'], 'catalogo' => [
            ['post_id' => ' 7431-1234 ', 'valor' => 'Lanzamiento México'],
            ...self::REGLAS['arte']['catalogo'],
        ]]]);

        $this->assertSame('74311234', $reglas['arte']['catalogo'][0]['post_id']);
        $r = ReglasNomenclatura::parsear($reglas, 'tk_obmx_brn_do', 'El ASMR de los adultos chiquitos', null, null, '74311234');
        $this->assertSame('Lanzamiento México', $r['arte']);
        $this->assertSame('post 74311234', $r['reglasAplicadas']['arte']);
    }

    public function test_el_post_de_tiktok_viaja_a_la_card_y_su_regla_gana(): void
    {
        $this->openbank->update(['nomenclatura' => ReglasNomenclatura::normalizar([...self::REGLAS, 'arte' => [...self::REGLAS['arte'], 'catalogo' => [
            ['post_id' => '999', 'valor' => 'Lanzamiento México'], ...self::REGLAS['arte']['catalogo'],
        ]]])]);
        $af = [['adId' => '1', 'campaign' => 'tk_obmx_brn_do', 'adRaw' => 'Texto suelto del post', 'serie' => [['fecha' => '01-10-2026', 'installs' => 3, 'orders' => 1, 'newCustomers' => 1]]]];
        $costos = [['adId' => '1', 'adName' => 'Texto suelto del post', 'campaignName' => 'tk_obmx_brn_do', 'postId' => '999', 'cost' => 10, 'impressions' => 100, 'clicks' => 2]];

        $cards = CuentaActual::usar($this->openbank, function () use ($af, $costos) {
            $clasif = CruceCostosAppsFlyer::clasificarPorPlataforma($af);

            return CruceCostosAppsFlyer::cruzar($clasif['tiktok'], $costos, 'tiktok', false);
        });

        $this->assertSame('999', $cards[0]['postId']);
        $this->assertSame('Lanzamiento México', $cards[0]['arte']);
    }

    public function test_la_cola_agrupa_por_post_y_por_texto_y_suma_el_gasto(): void
    {
        $this->creativo('a1', null, 'Llega Openbank a México 🚀', '111', 100);
        $this->creativo('a2', null, 'Llega Openbank a México 🚀', '111', 50);
        $this->creativo('a3', null, '01_RMKT_VD2_9-16_DA7icyIH.mp4', null, 30);
        $this->creativo('a4', 'Outfit Match', 'fb_obmx_outfit-match', null, 999);

        $this->actingAs($this->epa())->get('/openbank/pais/mexico/ajustes/sin-creativo')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('SinCreativo')
                ->where('disponible', true)
                ->has('grupos', 2)
                ->where('grupos.0.post_id', '111')
                ->where('grupos.0.anuncios', 2)
                ->where('grupos.0.gasto', 150)
                ->where('grupos.1.texto', '01_RMKT_VD2_9-16_DA7icyIH.mp4')
                ->where('creativosExistentes', fn ($c) => collect($c)->contains('Outfit Match')));
    }

    public function test_asignar_por_post_actualiza_los_anuncios_y_queda_en_el_catalogo(): void
    {
        $this->creativo('a1', null, 'Llega Openbank a México 🚀', '111', 100);
        $this->creativo('a2', null, 'Llega Openbank a México 🚀', '111', 50);

        $this->actingAs($this->epa())->postJson('/openbank/pais/mexico/ajustes/sin-creativo', ['post_id' => '111', 'nombre' => 'Lanzamiento México'])
            ->assertOk()->assertJsonPath('actualizados', 2);

        CuentaActual::usar($this->openbank, fn () => $this->assertSame(2, Creativo::where('nombre_comun', 'Lanzamiento México')->count()));
        $this->assertSame(['post_id' => '111', 'valor' => 'Lanzamiento México'], $this->reglas()['arte']['catalogo'][0]);
        // Un anuncio nuevo de ese post ya sale con el creativo.
        $this->assertSame('Lanzamiento México', ReglasNomenclatura::parsear($this->reglas(), 'tk_obmx_x', 'otro texto', null, null, '111')['arte']);
    }

    public function test_asignar_por_texto_usa_el_texto_exacto(): void
    {
        $this->creativo('a3', null, '01_RMKT_VD2_9-16 (2)_DA7icyIH.mp4', null, 30);

        $this->actingAs($this->epa())->postJson('/openbank/pais/mexico/ajustes/sin-creativo', ['texto' => '01_RMKT_VD2_9-16 (2)_DA7icyIH.mp4', 'nombre' => 'Remarketing VD2'])
            ->assertOk()->assertJsonPath('actualizados', 1);

        $reglas = $this->reglas();
        $this->assertSame('Remarketing VD2', ReglasNomenclatura::parsear($reglas, null, '01_RMKT_VD2_9-16 (2)_DA7icyIH.mp4')['arte']);
        // Exacto: un texto parecido no coincide.
        $this->assertNotSame('Remarketing VD2', ReglasNomenclatura::parsear($reglas, null, '01_RMKT_VD2_9-16 (3)_DA7icyIH.mp4')['arte']);
    }

    public function test_sin_clasificacion_propia_o_en_tada_no_se_puede_asignar(): void
    {
        $this->openbank->update(['nomenclatura' => null]);

        $this->actingAs($this->epa())->postJson('/openbank/pais/mexico/ajustes/sin-creativo', ['post_id' => '1', 'nombre' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors(['nombre']);

        $this->actingAs($this->epa())->get('/tada/pais/mexico/ajustes/sin-creativo')
            ->assertInertia(fn ($page) => $page->where('disponible', false)->where('esTada', true));
    }

    public function test_proponer_con_ia_conserva_el_catalogo_pegado(): void
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['version' => 2, 'funnel' => [['nombre' => 'Do', 'equivalente' => 'CNV', 'siglas' => ['do']]]])]]])]);

        $this->actingAs($this->epa())->postJson('/cuentas/nomenclatura/proponer', [
            'anuncios' => ['fb_obmx_lead_do_a', 'fb_obmx_lead_do_b', 'fb_obmx_lead_do_outfit-match'],
            'catalogo' => [['patron' => '_outfit-match', 'valor' => 'Outfit Match']],
        ])->assertOk()
            ->assertJsonPath('reglas.arte.catalogo.0.valor', 'Outfit Match')
            ->assertJsonPath('vista_previa.2.arte', 'Outfit Match');
    }

    private function creativo(string $adId, ?string $arte, string $nombre, ?string $postId, float $gasto): void
    {
        CuentaActual::usar($this->openbank, function () use ($adId, $arte, $nombre, $postId, $gasto) {
            $c = Creativo::create([
                'ad_id' => $adId, 'pais_id' => $this->mx->id, 'nombre_comun' => $arte, 'nombre_completo' => $nombre,
                'plataforma' => 'tiktok', 'funnel' => 'CNV', 'tipo_cuenta' => 'DTC', 'post_id' => $postId,
            ]);
            Resultado::create([
                'creativo_id' => $c->id, 'mes' => '2026-10', 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31',
                'cost' => $gasto, 'impressions' => 0, 'clicks' => 0, 'installs' => 0, 'reorders' => 0, 'tiene_meta' => true,
            ]);
        });
    }

    private function epa(): User
    {
        $user = User::factory()->create(['rol' => 'junior']);
        $user->paises()->attach($this->mx->id);
        $user->cuentas()->attach($this->openbank->id);

        return $user;
    }
}
