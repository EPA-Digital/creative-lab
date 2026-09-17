<?php

namespace App\Console\Commands;

use App\Services\Ingesta\ImportadorDatosDiario;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Comando del pipeline diario (2026-08-27, ver plan del selector de fecha)
 * -- equivalente a importar:csv pero para el export de Snowflake (costo/
 * impresiones/clicks de Meta ya cruzado con AppsFlyer, por día). Sin
 * desde/hasta: la columna Date de cada fila ya lo decide, y un mismo
 * archivo puede traer varios meses de una sola pasada (a diferencia de
 * importar:csv, que exige un solo mes por corrida).
 */
class ImportarCsvDiario extends Command
{
    protected $signature = 'importar:csv-diario
        {archivo : Ruta al CSV de Snowflake}
        {pais : Slug de config/paises.php (ej. panama)}';

    protected $description = 'Importa el export diario de Snowflake (costo/impresiones/clicks + AppsFlyer) y recalcula los resultados mensuales afectados';

    public function handle(): int
    {
        // Mismo motivo que ImportarCsvAppsFlyer/ImportarAppsFlyerApi -- este
        // export puede traer decenas de miles de filas (varios meses de
        // una sola pasada, a diferencia de importar:csv que es un mes).
        ini_set('memory_limit', '512M');

        $archivo = $this->argument('archivo');
        if (! is_file($archivo)) {
            $this->error("No se encontró el archivo: {$archivo}");

            return self::FAILURE;
        }

        $this->info('Parseando CSV diario y corriendo el pipeline...');

        try {
            $resumen = ImportadorDatosDiario::importar($archivo, $this->argument('pais'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($resumen['problemas'] > 0) {
            $this->warn("{$resumen['problemas']} fila(s) con problemas en el CSV -- revisar a mano.");
        }
        if ($resumen['excluidos'] > 0) {
            $this->line("{$resumen['excluidos']} fila(s) excluida(s) (sin bloque FB-/TKT- reconocible).");
        }
        if ($resumen['sinActividadDescartados'] > 0) {
            $this->line("{$resumen['sinActividadDescartados']} fila(s) descartada(s) por no tener actividad real ese día.");
        }

        $this->info("Listo: {$resumen['creativosTocados']} creativo(s) creados/actualizados, {$resumen['diasTocados']} día(s)-anuncio guardados, {$resumen['mesesTocados']} mes(es)-creativo recalculados en resultados.");

        return self::SUCCESS;
    }
}
