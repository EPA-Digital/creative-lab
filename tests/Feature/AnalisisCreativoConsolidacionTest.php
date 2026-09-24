<?php

use App\Http\Controllers\AnalisisCreativoController;
use App\Models\Creativo;
use App\Models\Resultado;
use App\Services\Ingesta\VentaRealYAgrupacion;

/**
 * Cubre el wiring de 2026-09-17 (spec Adenda A): AnalisisCreativoController
 * ya NO manda una card por Ad ID -- arma cards y las consolida vía
 * VentaRealYAgrupacion::agruparPorArteYFunnel() antes de mandarlas a Inertia.
 * Como el proyecto no corre RefreshDatabase en Feature tests (ver
 * tests/Pest.php, migraciones con `MODIFY` no corren en sqlite), estos tests
 * usan modelos SIN GUARDAR (`new Creativo([...])` + `setRelation`) igual que
 * el resto de la suite de Ingesta -- prueban los métodos privados de mapeo
 * vía Reflection, sin tocar la base de datos.
 *
 * Caso real que reproduce (verificado a mano contra Panamá, julio 2026,
 * arte "RGB-GOLDEN"): el mismo arte corre en DOS ad_ids de la etapa CNV
 * (cost 27.26 + 85.34 = 112.60, nc 0+11=11) Y en DOS ad_ids de la etapa CONS
 * (cost 1.49+2.85=4.34) -- deben salir TRES filas: 1 fila CNV consolidada,
 * 1 fila CONS consolidada, nunca las 4 juntas ni CNV+CONS mezclados.
 */
function metodoPrivado(object $objeto, string $nombre): ReflectionMethod
{
    $metodo = new ReflectionMethod($objeto, $nombre);
    $metodo->setAccessible(true);

    return $metodo;
}

function creativoConResultado(int $id, string $adId, string $arte, string $funnel, float $cost, float $nc): Creativo
{
    $creativo = new Creativo([
        'ad_id' => $adId,
        'nombre_comun' => $arte,
        'nombre_completo' => $arte,
        'nombre_campania' => 'CAMPANIA-'.$adId,
        'plataforma' => 'meta',
        'tipo_cuenta' => 'DTC',
        'formato' => 'IMAGEN',
        'funnel' => $funnel,
        'imagen_url' => null,
        'copy' => null,
    ]);
    $creativo->id = $id;

    $resultado = new Resultado([
        'cost' => $cost, 'impressions' => 1000, 'clicks' => 50, 'installs' => 20,
        'nc' => $nc, 'orders' => 0, 'cac' => $nc > 0 ? $cost / $nc : null,
        'cpo' => null, 'cpi' => 15.5, 'ctr' => 5.0, 'cpm' => 12.0, 'tiene_meta' => true,
    ]);
    $creativo->setRelation('resultados', collect([$resultado]));
    // El controller real hace ->with('correccionNombre') -- acá se emula
    // sin relación (ningún test necesita nombre_amigable) para que el
    // accessor no dispare un lazy-load real contra la BD (no hay
    // migraciones corridas en sqlite, ver docblock del archivo).
    $creativo->setRelation('correccionNombre', null);

    return $creativo;
}

it('consolida por arte+funnel el mismo caso real de Panamá (RGB-GOLDEN, CNV y CONS separados)', function () {
    $controller = app(AnalisisCreativoController::class);
    $creativos = collect([
        creativoConResultado(1, '120245819365050680', 'RGB-GOLDEN', 'CNV', 27.26, 0),
        creativoConResultado(2, '120245819419690680', 'RGB-GOLDEN', 'CNV', 85.34, 11),
        creativoConResultado(3, '120245816880880680', 'RGB-GOLDEN', 'CONS', 1.49, 0),
        creativoConResultado(4, '120245817325530680', 'RGB-GOLDEN', 'CONS', 2.85, 0),
    ]);

    $cards = metodoPrivado($controller, 'cardsDesdeMes')->invoke($controller, $creativos);
    expect($cards)->toHaveCount(4);

    $agrupado = VentaRealYAgrupacion::agruparPorArteYFunnel($cards);
    expect($agrupado['stats']['grupos'])->toBe(2)
        ->and($agrupado['stats']['filasSueltas'])->toBe(0);

    $jsons = array_map(
        fn (array $c) => metodoPrivado($controller, 'cardAJson')->invoke($controller, $c, '2026-07'),
        $agrupado['cards'],
    );
    expect($jsons)->toHaveCount(2);

    $cnv = collect($jsons)->firstWhere('funnel', 'CNV');
    $cons = collect($jsons)->firstWhere('funnel', 'CONS');

    expect($cnv['esGrupoArte'])->toBeTrue()
        ->and($cnv['resultados'][0]['cost'])->toBe(27.26 + 85.34)
        ->and($cnv['resultados'][0]['nc'])->toEqual(11)
        // CAC recalculado desde los totales sumados, no promediado ni tomado de un solo miembro.
        ->and($cnv['resultados'][0]['cac'])->toBe((27.26 + 85.34) / 11);

    expect($cons['esGrupoArte'])->toBeTrue()
        ->and($cons['resultados'][0]['cost'])->toBe(1.49 + 2.85)
        ->and($cons['resultados'][0]['nc'])->toEqual(0)
        // Sin NC, CAC debe ser null -- nunca 0 ni división por cero.
        ->and($cons['resultados'][0]['cac'])->toBeNull();
});

it('un arte sin par en su etapa sigue como grupo de 1 miembro (nunca se mezcla con otra etapa)', function () {
    // esGrupoArte es true para CUALQUIER card con arte+funnel válidos, tenga
    // 1 miembro o varios -- solo arte/funnel faltantes (o "Sin clasificar")
    // quedan realmente "sueltos" (ver el test de abajo). El frontend decide
    // si vale la pena mostrar el desglose de miembros mirando
    // miembros.length > 1, no esGrupoArte a secas.
    $controller = app(AnalisisCreativoController::class);
    $creativos = collect([
        creativoConResultado(1, '111', 'ARTE-UNICO', 'AWA', 50.0, 0),
    ]);

    $cards = metodoPrivado($controller, 'cardsDesdeMes')->invoke($controller, $creativos);
    $agrupado = VentaRealYAgrupacion::agruparPorArteYFunnel($cards);
    $json = metodoPrivado($controller, 'cardAJson')->invoke($controller, $agrupado['cards'][0], '2026-07');

    expect($agrupado['stats']['grupos'])->toBe(1)
        ->and($json['esGrupoArte'])->toBeTrue()
        ->and($json['miembros'])->toHaveCount(1)
        ->and($json['ad_id'])->toBe('111')
        ->and($json['funnel'])->toBe('AWA');
});

it('un creativo sin arte clasificado queda "Sin clasificar" -> funnel null en el JSON, nunca agrupado', function () {
    $controller = app(AnalisisCreativoController::class);
    $creativo = creativoConResultado(1, '222', 'ARTE-X', 'AWA', 10.0, 0);
    $creativo->funnel = null;
    $creativos = collect([$creativo]);

    $cards = metodoPrivado($controller, 'cardsDesdeMes')->invoke($controller, $creativos);
    expect($cards[0]['etapaFunnel'])->toBe('Sin clasificar');

    $agrupado = VentaRealYAgrupacion::agruparPorArteYFunnel($cards);
    $json = metodoPrivado($controller, 'cardAJson')->invoke($controller, $agrupado['cards'][0], '2026-07');

    expect($json['funnel'])->toBeNull();
});
