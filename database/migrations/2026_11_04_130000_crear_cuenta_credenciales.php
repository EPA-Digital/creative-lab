<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Credenciales por cuenta (pedido explícito 2026-11-04): cada cuenta pone su
// propio token de AppsFlyer -- el de TaDa no sirve para Openbank y no hay
// uno "general". Sin token, la cuenta queda en "Sin token" y no importa por
// API. El token se guarda cifrado con APP_KEY (cast `encrypted`).
//
// El APPSFLYER_API_TOKEN que hasta hoy vivía en el entorno era el de TaDa:
// se copia a la cuenta TaDa con sus eventos reales del SDK, para que la
// importación de TaDa siga igual sin que nadie toque nada.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuenta_credenciales', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('cuenta_id');
            $table->foreign('cuenta_id')->references('id')->on('cuentas')->cascadeOnDelete();
            $table->string('proveedor', 30); // appsflyer (meta/tiktok/bigquery más adelante)
            $table->text('token')->nullable();
            $table->string('ultimos4', 4)->nullable();
            $table->json('ajustes')->nullable(); // appsflyer: evento_principal, evento_secundario
            $table->timestamp('validado_en')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->foreign('actualizado_por')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->nullable();
            $table->unique(['cuenta_id', 'proveedor']);
        });

        $tada = DB::table('cuentas')->where('slug', 'tada')->value('id');
        $token = config('services.appsflyer.api_token');
        if ($tada) {
            DB::table('cuenta_credenciales')->insert([
                'cuenta_id' => $tada,
                'proveedor' => 'appsflyer',
                'token' => $token ? Crypt::encryptString($token) : null,
                'ultimos4' => $token ? substr($token, -4) : null,
                'ajustes' => json_encode(['evento_principal' => 'First Order Placed', 'evento_secundario' => 'Order Placed']),
                'creado_en' => now(),
                'actualizado_en' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cuenta_credenciales');
    }
};
