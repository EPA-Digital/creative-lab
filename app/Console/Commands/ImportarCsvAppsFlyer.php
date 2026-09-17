<?php

namespace App\Console\Commands;

use App\Services\Ingesta\ImportadorDatos;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Comando de import de punta a punta -- equivalente a "subir el CSV +
 * npm run meta + npm run tiktok-api + escribir los totales de venta real"
 * del dashboard Node, pero persistiendo en creativos/resultados en vez de
 * mostrar cards en el navegador. Wrapper delgado sobre ImportadorDatos --
 * el panel ImportarDatos.vue (vía ImportarDatosController) llama a la misma
 * clase, así que este comando y ese panel nunca pueden desincronizarse.
 *
 * Un creativo -> muchos resultados (uno por mes, ver esquema-bd-core-v1.2)
 * -- este comando se corre UN MES A LA VEZ (mismo flujo documentado:
 * "Cuando cargas un mes (ej. abril 2026)..."), no auto-divide un rango que
 * cruce meses.
 */
class ImportarCsvAppsFlyer extends Command
{
    protected $signature = 'importar:csv
        {archivo : Ruta al CSV de AppsFlyer}
        {pais : Slug de config/paises.php (ej. ecuador)}
        {desde : Fecha de inicio del rango, YYYY-MM-DD}
        {hasta : Fecha de fin del rango, YYYY-MM-DD}
        {--nc-total-real-meta= : NC total real de Meta para el rango}
        {--orders-total-real-meta= : Orders total real de Meta para el rango}
        {--nc-total-real-tiktok= : NC total real de TikTok para el rango}
        {--orders-total-real-tiktok= : Orders total real de TikTok para el rango}';

    protected $description = 'Importa el CSV de AppsFlyer + costos de Meta/TikTok, calcula venta real y persiste en creativos/resultados';

    public function handle(): int
    {
        // Ver nota en ImportarAppsFlyerApi::handle() -- mismo pipeline de
        // caché de imágenes, mismo riesgo de memory_limit real con cuentas
        // grandes.
        ini_set('memory_limit', '512M');

        $archivo = $this->argument('archivo');
        if (! is_file($archivo)) {
            $this->error("No se encontró el archivo: {$archivo}");

            return self::FAILURE;
        }

        $this->info('Parseando CSV y corriendo el pipeline...');

        try {
            $resumen = ImportadorDatos::importar(
                $archivo,
                $this->argument('pais'),
                $this->argument('desde'),
                $this->argument('hasta'),
                $this->option('nc-total-real-meta'),
                $this->option('orders-total-real-meta'),
                $this->option('nc-total-real-tiktok'),
                $this->option('orders-total-real-tiktok'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($resumen['esRangoParcial']) {
            // esRangoParcial ya no es un flag manual (--parcial) -- se
            // detecta solo comparando desde/hasta contra el rango real del
            // CSV completo, igual que calcularCardsFinal en motor.js.
            $this->warn('Rango parcial (no cubre todo el CSV) -- ncReal/ordersReal/cac/cpo/cpi quedan null, no se pueden prorratear a un sub-rango.');
        }
        if ($resumen['problemas'] > 0) {
            $this->warn("{$resumen['problemas']} fila(s) con problemas en el CSV -- revisar a mano.");
        }
        if ($resumen['excluidos'] > 0) {
            $this->line("{$resumen['excluidos']} ad(s) excluido(s) (sin bloque FB-/TKT- reconocible).");
        }
        if ($resumen['sinActividadDescartados'] > 0) {
            $this->line("{$resumen['sinActividadDescartados']} ad(s) descartado(s) por no tener actividad real (cost/impressions/clicks/installs = 0).");
        }

        $this->info("Listo: {$resumen['creativosTocados']} creativo(s) creados/actualizados, {$resumen['resultadosTocados']} resultado(s) del mes guardados, {$resumen['tieneMetaTrue']} con match real de costo (tiene_meta=true).");

        return self::SUCCESS;
    }
}
