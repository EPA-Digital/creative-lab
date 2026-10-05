<?php

use App\Models\CuentaPublicitaria;
use App\Models\Pais;
use App\Services\Ingesta\ClasificadorNombres;
use App\Services\Ingesta\ConciliacionImportacion;
use App\Services\Ingesta\ImportadorDatos;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Cuentas publicitarias por país + reparto de venta real (pedido explícito
 * 2026-10-05): el total real se reparte entre TODOS los canales (BRD, DTC paid y no paid), solo
 * de cuentas marcadas "cuenta para venta real". DTC no paid (MLM/NON) se
 * muestra aparte en la conciliación, solo informativo.
 */
uses(RefreshDatabase::class);

function metodoImportador(string $nombre): ReflectionMethod
{
    return new ReflectionMethod(ImportadorDatos::class, $nombre);
}

function cuenta(array $atributos = []): CuentaPublicitaria
{
    $pais = Pais::firstOrCreate(['codigo' => 'EC'], ['nombre' => 'Ecuador']);

    return CuentaPublicitaria::create([
        'pais_id' => $pais->id,
        'plataforma' => 'tiktok',
        'cuenta_id' => (string) random_int(1000, 999999),
        'nombre' => 'Cuenta',
        'tipo' => 'tada',
        ...$atributos,
    ]);
}

it('reconoce el DTC no paid por los segmentos MLM/NON, y deja pasar BRD y DTC paid', function () {
    expect(ClasificadorNombres::esDtcNoPaid('ECU_DTC_MLM_AON_AON_NON_CON_EPA_TKT-INSTALL-AND-VOLUME'))->toBeTrue();
    expect(ClasificadorNombres::esDtcNoPaid('MEX_DTC_MLM_TADABI_ALWAYSON_NON_CNV_ZNT_TADA-COUT-FB-MULM-MX-CONV'))->toBeTrue();
    expect(ClasificadorNombres::esDtcNoPaid('ECU_DTC_TAD_AON_AON_TAD_CNV_EPA_TKT-CONVERSION-AND-SMART'))->toBeFalse();
    expect(ClasificadorNombres::esDtcNoPaid('ECU_BRD_MLM_AON_AON_NON_CNV_EPA_FB-ADVANTAGE-AND-SHOPPING-BROAD'))->toBeFalse();
    expect(ClasificadorNombres::esDtcNoPaid('MAZ_ECU_LANZAMIENTO-NA-DTC_TAD_CNV-CNV_SUBASTA_FB-TADA-EC-CONV'))->toBeFalse();
    expect(ClasificadorNombres::esDtcNoPaid(null))->toBeFalse();
});

it('reparte entre todos los canales de las cuentas marcadas para venta real', function () {
    $dentro = cuenta(['cuenta_en_venta_real' => true]);
    $fuera = cuenta(['cuenta_en_venta_real' => false]);
    $cuentas = collect([$dentro, $fuera])->keyBy('id');
    $participa = fn (array $card) => metodoImportador('participaEnVentaReal')->invoke(null, $card, $cuentas);

    expect($participa(['campaignName' => 'ECU_DTC_TAD_AON_AON_TAD_CNV_EPA_TKT-X', 'cuentaPublicitariaId' => $dentro->id]))->toBeTrue();
    expect($participa(['campaignName' => 'ECU_BRD_MLM_AON_AON_NON_CNV_EPA_FB-X', 'cuentaPublicitariaId' => $dentro->id]))->toBeTrue();
    // DTC no paid también entra (confirmado contra el sheet de Ecuador sept 2026).
    expect($participa(['campaignName' => 'ECU_DTC_MLM_AON_AON_NON_CON_EPA_TKT-X', 'cuentaPublicitariaId' => $dentro->id]))->toBeTrue();
    expect($participa(['campaignName' => 'ECU_DTC_TAD_AON_AON_TAD_CNV_EPA_TKT-X', 'cuentaPublicitariaId' => $fuera->id]))->toBeFalse();
    // Solo AppsFlyer (sin costo, sin cuenta conocida) sigue entrando, igual que antes.
    expect($participa(['campaignName' => 'ECU_DTC_TAD_AON_AON_TAD_CNV_EPA_TKT-X', 'cuentaPublicitariaId' => null]))->toBeTrue();
});

it('junta los costos de todas las cuentas y etiqueta cada fila con su cuenta', function () {
    $a = cuenta(['cuenta_id' => '111']);
    $b = cuenta(['cuenta_id' => '222']);
    $porCuenta = [
        '111' => [['adId' => 'x1', 'cost' => 10.0], ['adId' => 'x2', 'cost' => 5.0]],
        '222' => [['adId' => 'y1', 'cost' => 7.0], ['adId' => 'x1', 'cost' => 99.0]],
    ];

    $filas = metodoImportador('traerCostosDeCuentas')->invoke(null, collect([$a, $b]), fn (string $id) => $porCuenta[$id]);

    expect(array_column($filas, 'cuentaPublicitariaId', 'adId'))->toBe(['x1' => $a->id, 'x2' => $a->id, 'y1' => $b->id]);
    expect(collect($filas)->firstWhere('adId', 'x1')['cost'])->toBe(10.0);
});

it('solo confirma "sin actividad"/"eliminado" lo que lo es en todas las cuentas', function () {
    $a = cuenta(['cuenta_id' => '111']);
    $b = cuenta(['cuenta_id' => '222']);
    // En la cuenta 111 el ad "vivo" existe; en la 222 no existe ninguno.
    $detectar = fn (string $cuentaId, array $adIds) => $cuentaId === '111' ? array_values(array_diff($adIds, ['vivo'])) : $adIds;

    $confirmados = metodoImportador('confirmadosEnTodasLasCuentas')->invoke(null, collect([$a, $b]), $detectar, ['vivo', 'muerto']);

    expect($confirmados)->toBe(['muerto']);
});

it('arma la conciliación por cuenta, sin cuenta y DTC no paid, y suma solo lo repartido', function () {
    $tada = cuenta(['nombre' => 'EC TikTok TaDa']);
    $brd = cuenta(['nombre' => 'EC TikTok BRD', 'tipo' => 'brd']);
    $c = new ConciliacionImportacion(collect([$tada, $brd]), '1039', '9831');

    $c->registrar(['cuentaPublicitariaId' => $tada->id, 'cost' => 100.5, 'newCustomers' => 48, 'orders' => 173, 'campaignName' => 'ECU_DTC_TAD_AON_AON_TAD_CNV_EPA_TKT-A'], true, 85, 753);
    $c->registrar(['cuentaPublicitariaId' => $tada->id, 'cost' => 20, 'newCustomers' => 10, 'orders' => 30, 'campaignName' => 'ECU_DTC_MLM_AON_AON_NON_CON_EPA_TKT-B'], false, null, null);
    $c->registrar(['cuentaPublicitariaId' => null, 'cost' => null, 'newCustomers' => 5, 'orders' => 9, 'campaignName' => 'ECU_BRD_MLM_AON_AON_NON_CNV_EPA_FB-C'], true, 9, 16);

    $r = $c->toArray(3);

    expect($r['totales'])->toBe(['nc_tecleado' => 1039.0, 'orders_tecleado' => 9831.0, 'nc_repartido' => 94, 'orders_repartido' => 769]);
    expect($r['cuentas'][0])->toMatchArray(['nombre' => 'EC TikTok TaDa', 'ads' => 2, 'costo' => 120.5, 'af_nc' => 58.0, 'nc' => 85]);
    expect($r['cuentas'][1])->toMatchArray(['nombre' => 'EC TikTok BRD', 'ads' => 0]);
    expect($r['sin_cuenta']['ads'])->toBe(1);
    expect($r['sin_cuenta']['campanias'][0]['campania'])->toBe('ECU_BRD_MLM_AON_AON_NON_CNV_EPA_FB-C');
    expect($r['dtc_no_paid']['ads'])->toBe(1);
    expect($r['dtc_no_paid']['af_nc'])->toBe(10.0);
    expect($r['sin_actividad_descartados'])->toBe(3);
});
