<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Channel (2026-11-17, pedido explícito): los años que el equipo capturó a
 * mano en channel.monthly / channel.weekly (2025) y que no salen de AF data.
 * Una fila por año, tipo (mes | semana), periodo (1-12 / 1-53) y canal.
 * Se carga con database/data/canales_2025.json para la cuenta TaDa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canales_historico', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cuenta_id');
            $table->unsignedInteger('pais_id');
            $table->unsignedSmallInteger('anio');
            $table->string('tipo', 10);
            $table->unsignedTinyInteger('periodo');
            $table->string('canal', 40);
            $table->unsignedInteger('installs')->default(0);
            $table->unsignedInteger('nc')->default(0);
            $table->unsignedInteger('ordenes')->default(0);

            $table->foreign('cuenta_id')->references('id')->on('cuentas')->cascadeOnDelete();
            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->unique(['cuenta_id', 'pais_id', 'anio', 'tipo', 'periodo', 'canal'], 'canales_historico_unico');
        });

        $this->cargar(database_path('data/canales_2025.json'));
    }

    /** Carga el JSON para la cuenta TaDa (si todavía no existe, no hace nada). */
    private function cargar(string $ruta): void
    {
        $cuentaId = DB::table('cuentas')->where('slug', 'tada')->value('id');
        if (! $cuentaId || ! is_file($ruta)) {
            return;
        }
        $datos = json_decode(file_get_contents($ruta), true);
        foreach ($datos['paises'] as $pais => $tipos) {
            $codigo = config("paises.{$pais}.codigo");
            $paisId = $codigo ? DB::table('paises')->where('codigo', $codigo)->value('id') : null;
            if (! $paisId) {
                continue;
            }
            $filas = [];
            foreach ($tipos as $tipo => $periodos) {
                foreach ($periodos as $i => $canales) {
                    foreach ($canales as $canal => [$installs, $nc, $ordenes]) {
                        $filas[] = [
                            'cuenta_id' => $cuentaId, 'pais_id' => $paisId, 'anio' => $datos['anio'], 'tipo' => $tipo,
                            'periodo' => $i + 1, 'canal' => $canal, 'installs' => $installs, 'nc' => $nc, 'ordenes' => $ordenes,
                        ];
                    }
                }
            }
            foreach (array_chunk($filas, 500) as $lote) {
                DB::table('canales_historico')->insert($lote);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('canales_historico');
    }
};
