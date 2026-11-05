<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Multi-cuenta (pedido explícito 2026-11-04) -- hasta acá todo colgaba del
// país y el dashboard era solo de TaDa. Se agregan Cliente (el grupo, ej.
// Coppel o AB InBev) y Cuenta (lo que se analiza, ej. TaDa o Coppel
// Branding) por encima del país, y toda tabla de negocio pasa a llevar
// cuenta_id. Lo existente se asigna a TaDa en esta misma migración.
//
// cuentas_publicitarias ya tenía una columna `cuenta_id` (el ID en Meta /
// TikTok) -- se renombra a `id_externo` para que `cuenta_id` signifique lo
// mismo en todas las tablas.
return new class extends Migration
{
    /** Tablas de negocio que pasan a pertenecer a una cuenta. */
    private const TABLAS = [
        'creativos',
        'importaciones',
        'venta_real',
        'cuentas_publicitarias',
        'appsflyer_apps',
        'tareas_medios',
        'estado_creativo_eventos',
        'correcciones_nombres',
    ];

    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nombre', 120)->unique();
            $table->timestamp('creado_en')->useCurrent();
        });

        Schema::create('cuentas', function (Blueprint $table) {
            // increments/unsignedInteger, no id()/foreignId() -- mismo
            // criterio que paises/creativos (INT UNSIGNED).
            $table->increments('id');
            $table->unsignedInteger('cliente_id');
            $table->foreign('cliente_id')->references('id')->on('clientes')->restrictOnDelete();
            $table->string('nombre', 120);
            $table->string('slug', 60)->unique();
            $table->string('color', 7)->default('#9184d9');
            $table->string('logo_url')->nullable();
            $table->json('servicios');
            $table->json('objetivos');
            $table->json('medios');
            $table->boolean('fuente_ga4')->default(false);
            $table->boolean('fuente_mmp')->default(false);
            $table->string('venta_real', 10)->default('no'); // no | manual | auto
            $table->boolean('multi_pais')->default(false);
            $table->boolean('activa')->default(true);
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->nullable();
        });

        Schema::create('cuenta_pais', function (Blueprint $table) {
            $table->unsignedInteger('cuenta_id');
            $table->unsignedInteger('pais_id');
            $table->foreign('cuenta_id')->references('id')->on('cuentas')->cascadeOnDelete();
            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->primary(['cuenta_id', 'pais_id']);
        });

        $clienteId = DB::table('clientes')->insertGetId(['nombre' => 'AB InBev']);
        $tadaId = DB::table('cuentas')->insertGetId([
            'cliente_id' => $clienteId,
            'nombre' => 'TaDa',
            'slug' => 'tada',
            'color' => '#9184d9',
            'servicios' => json_encode(['App', 'Ecommerce']),
            'objetivos' => json_encode(['Instalaciones', 'Ventas', 'Recompra']),
            'medios' => json_encode(['meta', 'tiktok']),
            'fuente_mmp' => true,
            'venta_real' => 'manual',
            'multi_pais' => true,
        ]);
        foreach (DB::table('paises')->pluck('id') as $paisId) {
            DB::table('cuenta_pais')->insert(['cuenta_id' => $tadaId, 'pais_id' => $paisId]);
        }

        Schema::table('cuentas_publicitarias', function (Blueprint $table) {
            $table->renameColumn('cuenta_id', 'id_externo');
        });

        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->unsignedInteger('cuenta_id')->nullable()->after('id');
            });
            DB::table($tabla)->update(['cuenta_id' => $tadaId]);
            Schema::table($tabla, function (Blueprint $table) {
                $table->unsignedInteger('cuenta_id')->nullable(false)->change();
                $table->foreign('cuenta_id')->references('id')->on('cuentas')->restrictOnDelete();
            });
        }

        // Llaves únicas: el mismo ad_id (o la misma captura de venta real)
        // puede existir en dos cuentas sin chocar. Donde la FK de pais_id se
        // apoyaba en el índice único viejo, se le da un índice propio antes.
        Schema::table('creativos', function (Blueprint $table) {
            $table->dropUnique(['ad_id', 'pais_id']);
            $table->unique(['cuenta_id', 'ad_id', 'pais_id']);
        });
        Schema::table('venta_real', function (Blueprint $table) {
            $table->index('pais_id');
            $table->dropUnique(['pais_id', 'plataforma', 'mes']);
            $table->unique(['cuenta_id', 'pais_id', 'plataforma', 'mes']);
        });
        Schema::table('appsflyer_apps', function (Blueprint $table) {
            $table->index('pais_id');
            $table->dropUnique(['pais_id', 'plataforma']);
            $table->unique(['cuenta_id', 'pais_id', 'plataforma']);
        });
        Schema::table('estado_creativo_eventos', function (Blueprint $table) {
            $table->index('pais_id');
            $table->dropUnique('estado_evento_unico');
            $table->unique(['cuenta_id', 'pais_id', 'ad_id', 'evento_en', 'estado_nuevo'], 'estado_evento_unico');
        });
        Schema::table('correcciones_nombres', function (Blueprint $table) {
            $table->dropUnique(['ad_id']);
            $table->unique(['cuenta_id', 'ad_id']);
        });
    }

    public function down(): void
    {
        Schema::table('correcciones_nombres', function (Blueprint $table) {
            $table->dropUnique(['cuenta_id', 'ad_id']);
            $table->unique(['ad_id']);
        });
        Schema::table('estado_creativo_eventos', function (Blueprint $table) {
            $table->dropUnique('estado_evento_unico');
            $table->unique(['pais_id', 'ad_id', 'evento_en', 'estado_nuevo'], 'estado_evento_unico');
            $table->dropIndex(['pais_id']);
        });
        Schema::table('appsflyer_apps', function (Blueprint $table) {
            $table->dropUnique(['cuenta_id', 'pais_id', 'plataforma']);
            $table->unique(['pais_id', 'plataforma']);
            $table->dropIndex(['pais_id']);
        });
        Schema::table('venta_real', function (Blueprint $table) {
            $table->dropUnique(['cuenta_id', 'pais_id', 'plataforma', 'mes']);
            $table->unique(['pais_id', 'plataforma', 'mes']);
            $table->dropIndex(['pais_id']);
        });
        Schema::table('creativos', function (Blueprint $table) {
            $table->dropUnique(['cuenta_id', 'ad_id', 'pais_id']);
            $table->unique(['ad_id', 'pais_id']);
        });

        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropForeign(['cuenta_id']);
                $table->dropColumn('cuenta_id');
            });
        }

        Schema::table('cuentas_publicitarias', function (Blueprint $table) {
            $table->renameColumn('id_externo', 'cuenta_id');
        });

        Schema::dropIfExists('cuenta_pais');
        Schema::dropIfExists('cuentas');
        Schema::dropIfExists('clientes');
    }
};
