<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Divisas (2026-11-11): todo el dashboard reporta en USD. Hasta ahora el
 * gasto se guardaba en la moneda de cada cuenta publicitaria y TikTok
 * México (MXN) se mezclaba con Meta (USD).
 *
 * - cuentas_publicitarias.moneda: la de la cuenta en la plataforma (se
 *   detecta sola al importar; null = todavía no se sabe).
 * - tipos_cambio: cuántas unidades de la moneda equivalen a 1 USD, desde
 *   una fecha. El equipo lo actualiza una vez al año en Ajustes; cada día de
 *   gasto usa el vigente ese día.
 * - Lo ya importado de TikTok México se pasa a USD una sola vez con el tipo
 *   de cambio vigente (18.677168).
 */
return new class extends Migration
{
    private const MXN_POR_USD = 18.677168;

    public function up(): void
    {
        Schema::table('cuentas_publicitarias', function (Blueprint $table) {
            $table->char('moneda', 3)->nullable()->after('id_externo');
        });

        Schema::create('tipos_cambio', function (Blueprint $table) {
            $table->id();
            $table->char('moneda', 3);
            $table->date('vigente_desde');
            // Unidades de `moneda` por 1 USD (ej. 18.677168 MXN).
            $table->decimal('por_usd', 14, 6);
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('creado_en')->nullable();
            $table->timestamp('actualizado_en')->nullable();
            $table->unique(['moneda', 'vigente_desde']);
        });

        DB::table('tipos_cambio')->insert([
            'moneda' => 'MXN',
            'vigente_desde' => '2026-01-01',
            'por_usd' => self::MXN_POR_USD,
            'creado_en' => now(),
            'actualizado_en' => now(),
        ]);

        // TikTok México reporta en MXN (confirmado contra la API); el resto de
        // las cuentas, en USD. Las que no se conocen se detectan al importar.
        $mexico = DB::table('paises')->where('codigo', 'MX')->value('id');
        if (! $mexico) {
            return;
        }
        DB::table('cuentas_publicitarias')->where('pais_id', $mexico)->where('plataforma', 'tiktok')->update(['moneda' => 'MXN']);

        $creativos = DB::table('creativos')->where('pais_id', $mexico)->where('plataforma', 'tiktok')->pluck('id');
        foreach ($creativos->chunk(500) as $tanda) {
            $ids = $tanda->all();
            $r = self::MXN_POR_USD;
            DB::table('resultados')->whereIn('creativo_id', $ids)->update([
                'cost' => DB::raw("cost / {$r}"),
                'cpi' => DB::raw("cpi / {$r}"),
                'cac' => DB::raw("cac / {$r}"),
                'cpo' => DB::raw("cpo / {$r}"),
                'cpm' => DB::raw("cpm / {$r}"),
            ]);
            DB::table('resultados_diarios')->whereIn('creativo_id', $ids)->update([
                'cost' => DB::raw("cost / {$r}"),
            ]);
        }
    }

    public function down(): void
    {
        $mexico = DB::table('paises')->where('codigo', 'MX')->value('id');
        if ($mexico) {
            $r = self::MXN_POR_USD;
            $ids = DB::table('creativos')->where('pais_id', $mexico)->where('plataforma', 'tiktok')->pluck('id')->all();
            foreach (array_chunk($ids, 500) as $tanda) {
                DB::table('resultados')->whereIn('creativo_id', $tanda)->update([
                    'cost' => DB::raw("cost * {$r}"),
                    'cpi' => DB::raw("cpi * {$r}"),
                    'cac' => DB::raw("cac * {$r}"),
                    'cpo' => DB::raw("cpo * {$r}"),
                    'cpm' => DB::raw("cpm * {$r}"),
                ]);
                DB::table('resultados_diarios')->whereIn('creativo_id', $tanda)->update(['cost' => DB::raw("cost * {$r}")]);
            }
        }

        Schema::dropIfExists('tipos_cambio');
        Schema::table('cuentas_publicitarias', function (Blueprint $table) {
            $table->dropColumn('moneda');
        });
    }
};
