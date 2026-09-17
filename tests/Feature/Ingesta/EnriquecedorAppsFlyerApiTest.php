<?php

use App\Services\Ingesta\AppsFlyerApiClient;
use App\Services\Ingesta\EnriquecedorAppsFlyerApi;
use Illuminate\Support\Facades\Http;

/**
 * Cubre EnriquecedorAppsFlyerApi con respuestas realistas del Master API de
 * AppsFlyer (2026-08-11) -- las filas de ejemplo son formas REALES vistas al
 * verificar en vivo contra la cuenta de Ecuador (iOS id1596944067 + Android
 * ec.com.fiestacerca), no inventadas: incluyen el caso ASA (Campaign/Campaign
 * ID presentes pero Ad/Ad ID = "None", canal sin granularidad de creativo) y
 * el caso totalmente orgánico (todo "None").
 */
function csvAppsFlyerFake(array $filas): string
{
    $header = 'Campaign,Campaign ID,Ad,Ad ID,Install Time,Installs,Event Counter - First Order Placed,Event Counter - Order Placed';

    return implode("\n", [$header, ...array_map(fn (array $f) => implode(',', $f), $filas)]);
}

it('parsea una fila normal con Ad ID real a formato interno (fecha DD-MM-YYYY, NC/Orders desde los eventos correctos)', function () {
    Http::fake([
        'hq1.appsflyer.com/*' => Http::response(csvAppsFlyerFake([
            ['ECU_DTC_TAD_AON_FB-CONVERSION', '120243399981850661', 'IMG_ARTE', '120249684982530661', '2026-07-15', '10', '2', '5'],
        ])),
    ]);

    $enriquecedor = new EnriquecedorAppsFlyerApi(new AppsFlyerApiClient('token-de-prueba'));
    $r = $enriquecedor->enriquecer(['id1596944067'], '2026-07-01', '2026-07-31');

    expect($r['limpias'])->toHaveCount(1);
    expect($r['limpias'][0])->toBe([
        'adId' => '120249684982530661',
        'campaign' => 'ECU_DTC_TAD_AON_FB-CONVERSION',
        'adRaw' => 'IMG_ARTE',
        'serie' => [
            ['fecha' => '15-07-2026', 'installs' => 10, 'orders' => 5, 'newCustomers' => 2],
        ],
    ]);
    expect($r['organico'])->toBeEmpty();
});

it('trata filas con Ad ID "None" (ASA u orgánico puro) como orgánico, sin romper el resto', function () {
    Http::fake([
        'hq1.appsflyer.com/*' => Http::response(csvAppsFlyerFake([
            ['ECU_DTC_TAD_ASA-INSTALL-IOS-BRAND', '1014095543', 'None', 'None', '2026-07-15', '19', '2', '4'],
            ['None', 'None', 'None', 'None', '2026-07-15', '52', '8', '44'],
        ])),
    ]);

    $enriquecedor = new EnriquecedorAppsFlyerApi(new AppsFlyerApiClient('token-de-prueba'));
    $r = $enriquecedor->enriquecer(['id1596944067'], '2026-07-01', '2026-07-31');

    expect($r['limpias'])->toBeEmpty();
    expect($r['organico'])->toHaveCount(2);
    expect($r['organico'][0]['installs'])->toBe(19);
});

it('combina 2 apps (iOS+Android) sumando el mismo Ad ID+fecha en vez de descartarlo como duplicado', function () {
    Http::fake([
        'hq1.appsflyer.com/api/master-agg-data/v4/app/id1596944067*' => Http::response(csvAppsFlyerFake([
            ['ECU_DTC_TAD_AON_TKT-CONVERSION', '111', 'VID_ARTE', 'AD123', '2026-07-15', '10', '2', '5'],
        ])),
        'hq1.appsflyer.com/api/master-agg-data/v4/app/ec.com.fiestacerca*' => Http::response(csvAppsFlyerFake([
            ['ECU_DTC_TAD_AON_TKT-CONVERSION', '111', 'VID_ARTE', 'AD123', '2026-07-15', '25', '3', '9'],
        ])),
    ]);

    $enriquecedor = new EnriquecedorAppsFlyerApi(new AppsFlyerApiClient('token-de-prueba'));
    $r = $enriquecedor->enriquecer(['id1596944067', 'ec.com.fiestacerca'], '2026-07-01', '2026-07-31');

    expect($r['limpias'])->toHaveCount(1);
    expect($r['limpias'][0]['serie'])->toHaveCount(1);
    expect($r['limpias'][0]['serie'][0])->toBe([
        'fecha' => '15-07-2026', 'installs' => 35, 'orders' => 14, 'newCustomers' => 5,
    ]);
    expect($r['problemas'])->toBeEmpty(); // sumarDuplicados=true -- nunca flaggea esto como problema
});

it('flaggea (sin romper el resto) una fila con cantidad de columnas inesperada', function () {
    Http::fake([
        'hq1.appsflyer.com/*' => Http::response(implode("\n", [
            'Campaign,Campaign ID,Ad,Ad ID,Install Time,Installs,Event Counter - First Order Placed,Event Counter - Order Placed',
            'FILA,ROTA,CON,MENOS,COLUMNAS',
            'ECU_DTC_TAD_AON_FB-X,111,ARTE,AD1,2026-07-15,5,1,2',
        ])),
    ]);

    $enriquecedor = new EnriquecedorAppsFlyerApi(new AppsFlyerApiClient('token-de-prueba'));
    $r = $enriquecedor->enriquecer(['id1596944067'], '2026-07-01', '2026-07-31');

    expect($r['limpias'])->toHaveCount(1);
    expect($r['problemas'])->toHaveCount(1);
    expect($r['problemas'][0]['fuente'])->toBe('AppsFlyer API');
});

it('llama al Master API con la auth (Bearer), groupings y kpis verificados en vivo', function () {
    Http::fake([
        'hq1.appsflyer.com/*' => Http::response(csvAppsFlyerFake([])),
    ]);

    $enriquecedor = new EnriquecedorAppsFlyerApi(new AppsFlyerApiClient('mi-token'));
    $enriquecedor->enriquecer(['id1596944067'], '2026-07-01', '2026-07-31');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://hq1.appsflyer.com/api/master-agg-data/v4/app/id1596944067?from=2026-07-01&to=2026-07-31&groupings=c%2Caf_c_id%2Caf_ad%2Caf_ad_id%2Cinstall_time&kpis=installs%2Cevent_counter_First%20Order%20Placed%2Cevent_counter_Order%20Placed'
            && $request->hasHeader('Authorization', 'Bearer mi-token');
    });
});
