<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Para identificar cada app en la página de Ajustes ("Ecuador iOS", "México
// Android") -- app_id solo no alcanza para reconocerla a simple vista.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appsflyer_apps', function (Blueprint $table) {
            $table->string('nombre', 100)->nullable()->after('app_id');
        });
    }

    public function down(): void
    {
        Schema::table('appsflyer_apps', function (Blueprint $table) {
            $table->dropColumn('nombre');
        });
    }
};
