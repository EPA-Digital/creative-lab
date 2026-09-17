<?php

use App\Services\Ingesta\SnowflakeCsvParser;

/**
 * Cubre el parser del export de Snowflake (2026-08-27, ver plan del
 * selector de fecha): columnas por alias (tolerante a variaciones de
 * header), fecha ISO (distinta del formato "Feb 11, 2026" de
 * AppsFlyerCsvParser), costo con signo de moneda ("$1,028.00"), y que
 * nc_real/orders_real se toman tal cual del CSV sin reprorratear nada acá.
 */
function csvSnowflake(string $filas): string
{
    $header = 'Date,Campaign name,Campaign ID,Ad name,Ad ID,Impressions,Link clicks,Cost,INSTALLS,NEW CUSTOMERS,REPURCHASES,ORDERS,NC REALES PAID,ORDENES REALES PAID,creative thumbnail';

    return $header."\n".$filas;
}

it('parsea una fila real con costo en formato moneda ($1,028.00)', function () {
    $csv = csvSnowflake('2026-01-01,PAN_DTC_TAD_A_SOC_FBK_NA,120227919262,CARO_MUL_STA_,120144169523,28533,1659,"$1,028.00",9,3,9,12,4.1,18.2,https://cdn.example.com/thumb.jpg');

    $r = SnowflakeCsvParser::parse($csv);

    expect($r['problemas'])->toBeEmpty();
    expect($r['filas'])->toHaveCount(1);
    $fila = $r['filas'][0];
    expect($fila['adId'])->toBe('120144169523');
    expect($fila['fecha'])->toBe('2026-01-01');
    expect($fila['impressions'])->toBe(28533);
    expect($fila['clicks'])->toBe(1659);
    expect($fila['cost'])->toBe(1028.0);
    expect($fila['installs'])->toBe(9);
    expect($fila['nc'])->toBe(3);
    expect($fila['repurchases'])->toBe(9);
    expect($fila['orders'])->toBe(12);
    expect($fila['ncReal'])->toBe(4.1);
    expect($fila['ordersReal'])->toBe(18.2);
    expect($fila['thumbnailUrl'])->toBe('https://cdn.example.com/thumb.jpg');
});

it('descarta una fila con Ad ID inválido (None) y otra con fecha no reconocida', function () {
    $csv = csvSnowflake(
        "2026-01-01,Campaña,1,Ad,None,100,1,1.00,1,0,0,0,0,0,\n"
        ."no-es-fecha,Campaña,1,Ad,555,100,1,1.00,1,0,0,0,0,0,\n"
    );

    $r = SnowflakeCsvParser::parse($csv);

    expect($r['filas'])->toBeEmpty();
    expect($r['problemas'])->toHaveCount(2);
});

it('tolera variaciones de header vía alias (Ad en vez de Ad name, Clicks en vez de Link clicks)', function () {
    $csv = "Date,Campaign,Campaign ID,Ad,Ad ID,Impressions,Clicks,Cost,Installs,New Customer,Repurchases,Orders,NC Real,Orders Real,thumbnail\n"
        ."2026-02-15,PAN_DTC,1,VID-ARTE,999,10,2,5.00,1,1,0,1,1,1,\n";

    $r = SnowflakeCsvParser::parse($csv);

    expect($r['problemas'])->toBeEmpty();
    expect($r['filas'])->toHaveCount(1);
    expect($r['filas'][0]['adId'])->toBe('999');
});

it('ubica el header real aunque el archivo traiga filas de resumen/título antes (caso real Panamá)', function () {
    // Estructura confirmada contra el archivo real: fila en blanco, fila de
    // totales, fila "FORMULADO/SNOWFLAKE/APPSFLYER" (section labels), y
    // RECIÉN en la fila 4 el header real -- con columnas extra a la
    // izquierda (NOMBRE COMÚN, TIPO DE CREATIVO, etc.) que el parser ignora
    // porque no están en ningún alias.
    $csv = ",,,,,,,,,,,,,,,,,,,,,,,,,,\n"
        .",,,,,,,,,,,,,,,0,43807783,\"\$474,612.00\",,12100,1396,11590,12986,100.00%,100.00%,1639,19589\n"
        ."FORMULADO,,,,,,SNOWFLAKE,,,,,,,,,,,,,APPSFLYER,,,,,200.00%,\"1,639\",\"19,589\"\n"
        ."NOMBRE COMÚN,TIPO DE CREATIVO,CAMPAÑA SHORT,FUNNEL,INICIO,FIN,Date,Campaign name,Campaign ID,Ad set name,Ad set ID,Ad name,Ad ID,Ad preview URL: mobile feed,Ad preview URL: desktop feed,Ad creative thumbnail URL,Impressions,Link clicks,Cost,INSTALLS,NEW CUSTOMERS,REPURCHASES,ORDERS,NC %share ,Ordenes %share,NC REALES PAID,ORDENES REALES PAID\n"
        ."MP-ARTE,VIDEO,SHORT,Conversions,22DIC,22DIC,2026-01-01,PAN_DTC_TAD_A_FB-CONVERSION,120223791926210680,SOC_FBK,120226883568900680,CARO_MUL_STA_AON_LLA_1_FB-CONVERSION-MP-ARTE,120235240920180680,https://preview-mobile,https://preview-desktop,https://thumb,344,50,\"\$3.00\",1,0,1,1,0.00%,0.01%,0,2\n";

    $r = SnowflakeCsvParser::parse($csv);

    expect($r['problemas'])->toBeEmpty();
    expect($r['filas'])->toHaveCount(1);
    $fila = $r['filas'][0];
    expect($fila['adId'])->toBe('120235240920180680');
    expect($fila['fecha'])->toBe('2026-01-01');
    expect($fila['cost'])->toBe(3.0);
    expect($fila['thumbnailUrl'])->toBe('https://thumb');
});

it('nc_real/orders_real quedan null cuando la columna no existe -- nunca 0 inventado', function () {
    $csv = "Date,Campaign name,Ad name,Ad ID,Impressions,Link clicks,Cost,INSTALLS,NEW CUSTOMERS,REPURCHASES,ORDERS\n"
        ."2026-03-01,Campaña,Ad,123,10,1,1.00,1,1,0,1\n";

    $r = SnowflakeCsvParser::parse($csv);

    expect($r['filas'][0]['ncReal'])->toBeNull();
    expect($r['filas'][0]['orders_real'] ?? null)->toBeNull();
});
