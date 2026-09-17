<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial real de encendido/apagado/etc. de cada ad (2026-08-28, pedido
 * explícito -- puerto del Google Apps Script "Historial de cambios Meta
 * Ads V9" que ya se usaba para México, solo la parte de estado de
 * anuncios). Existe porque Meta NO guarda este historial para siempre --
 * probado contra la cuenta real de Ecuador: un `since` de meses atrás
 * igual solo devuelve ~1 semana de eventos vía la Activity Log
 * (`act_.../activities`). La única forma de tener un rango de fechas
 * confiable a futuro ("encendido del 1 feb al 5 mar") es ir guardándolo
 * nosotros cada vez que corre el sync (ver
 * App\Services\Ingesta\MetaEstadoAdsSyncService), nunca pedirle a Meta el
 * pasado -- para ads que ya corrieron antes de que esto exista, no hay
 * forma de reconstruir el historial real.
 *
 * `creativo_id` nullable a propósito: un evento de estado puede llegar
 * ANTES de que el pipeline de import (CSV/Snowflake) haya creado la fila
 * de `creativos` para ese ad_id -- se guarda igual con el `ad_id` crudo
 * para no perder el evento, y se resuelve cuando exista.
 *
 * `estado_anterior`/`estado_nuevo` -- confirmado contra datos reales
 * (2026-08-28): Meta NO manda el enum crudo (ACTIVE/PAUSED/...) en
 * `extra_data.old_value`/`new_value` de update_ad_run_status, manda el
 * texto YA localizado según el idioma de la cuenta (visto en cuentas
 * LATAM: "Activo", "Inactivo", "Procesamiento pendiente", "Revisión
 * pendiente", "Eliminado", "Actualización necesaria") -- se guarda TAL
 * CUAL viene, nunca se re-traduce ni se descarta un valor por no ser
 * Activo/Inactivo (a diferencia del script viejo de Apps Script para
 * México, que solo registraba ese toggle binario). `extra_data` también
 * trae `run_status.{old_value,new_value}` con los códigos NUMÉRICOS
 * internos de Meta, sin documentación pública -- se guarda crudo por si
 * hace falta más adelante, no se usa para nada todavía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estado_creativo_eventos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pais_id');
            $table->string('ad_id', 50);
            $table->unsignedInteger('creativo_id')->nullable();
            $table->string('estado_anterior', 40)->nullable();
            $table->string('estado_nuevo', 40);
            $table->timestamp('evento_en');
            $table->string('actor_name', 120)->nullable();
            $table->json('extra_data')->nullable();
            $table->timestamp('creado_en')->useCurrent();

            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->foreign('creativo_id')->references('id')->on('creativos')->nullOnDelete();
            // Dedupe: el mismo evento (mismo ad, mismo instante, mismo
            // estado nuevo) nunca se guarda dos veces aunque el sync corra
            // varias veces sobre una ventana que se solapa a propósito (ver
            // MetaEstadoAdsSyncService::sincronizar, 1 hora de solape).
            $table->unique(['pais_id', 'ad_id', 'evento_en', 'estado_nuevo'], 'estado_evento_unico');
            $table->index('creativo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estado_creativo_eventos');
    }
};
