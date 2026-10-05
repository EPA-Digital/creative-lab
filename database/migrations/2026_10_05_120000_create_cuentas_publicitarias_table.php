<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Cuentas publicitarias por país (pedido explícito 2026-10-05) -- antes
// cada país tenía UNA cuenta de Meta y UNA de TikTok fijas en
// config/paises.php. Ecuador ya tiene una campaña BRD en otra cuenta de
// Meta y una cuenta nueva de TikTok; superadmin las administra desde
// Ajustes y elige cuáles entran al reparto de venta real.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_publicitarias', function (Blueprint $table) {
            // increments/unsignedInteger, no id()/foreignId() -- paises.id es
            // INT UNSIGNED (mismo criterio que creativos/appsflyer_apps); con
            // BIGINT MySQL rechaza la llave foránea.
            $table->increments('id');
            $table->unsignedInteger('pais_id');
            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->string('plataforma', 10); // meta | tiktok
            $table->string('cuenta_id', 50); // act_<id> sin el prefijo / advertiser_id
            $table->string('nombre', 150)->nullable();
            $table->string('tipo', 10)->default('tada'); // tada | brd
            $table->boolean('activa')->default(true);
            $table->boolean('cuenta_en_venta_real')->default(true);
            $table->timestamp('creado_en')->useCurrent();

            $table->unique(['plataforma', 'cuenta_id']);
        });

        // Punto de partida: las cuentas que hoy viven en config/paises.php,
        // para que la importación siga funcionando igual apenas se migra.
        foreach (config('paises') as $config) {
            $paisId = DB::table('paises')->where('codigo', $config['codigo'])->value('id');
            if (! $paisId) {
                continue;
            }
            foreach (['meta' => 'meta_ad_account_id', 'tiktok' => 'tiktok_advertiser_id'] as $plataforma => $clave) {
                if (! empty($config[$clave])) {
                    DB::table('cuentas_publicitarias')->insertOrIgnore([
                        'pais_id' => $paisId,
                        'plataforma' => $plataforma,
                        'cuenta_id' => $config[$clave],
                        'nombre' => "{$config['nombre']} ".($plataforma === 'meta' ? 'Meta' : 'TikTok').' (TaDa)',
                        'tipo' => 'tada',
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas_publicitarias');
    }
};
