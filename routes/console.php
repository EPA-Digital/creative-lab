<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule::command('importar:csv')->everyFiveMinutes();

// Sin {pais} -> modo multi-país (todos los habilitados con appsflyer_app_ids
// configurado), nunca pasa totales manuales (el comando los rechaza en ese
// modo) -- corre 1 vez al día, no hace falta más seguido: el buffer de
// atribución (5 días default) ya evita pedir datos todavía inestables, y
// nc/orders/cac/cpo de meses ya cerrados quedan protegidos por
// ImportadorDatos::resolverNcOrdersFinal (nunca se pisan sin totales nuevos).
// 11:00 UTC ~= 06:00 en Ecuador (UTC-5) -- ajustar si se agregan países en
// otro huso horario donde ese horario deje de tener sentido como "arranque
// del día".
Schedule::command('importar:appsflyer-api')
    ->dailyAt('11:00')
    ->withoutOverlapping();

// meta:sincronizar-estado-ads -- 2026-08-28, pedido explícito: Meta solo
// expone ~1 semana de Activity Log hacia atrás (confirmado contra la
// cuenta real de Ecuador), así que hay que correr esto con margen de sobra
// dentro de esa ventana para no perder eventos entre corrida y corrida --
// diario, no cada 2 días, para dejar colchón si un día falla (rate limit,
// token vencido) y el día siguiente igual recupera todo sin huecos.
Schedule::command('meta:sincronizar-estado-ads')
    ->dailyAt('11:15')
    ->withoutOverlapping();
