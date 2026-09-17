<?php

namespace Database\Seeders;

use App\Models\AppsflyerApp;
use App\Models\Pais;
use Illuminate\Database\Seeder;

class AppsflyerAppSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $apps = [
            'EC' => [
                'ios' => ['app_id' => 'id1596944067', 'nombre' => 'Ecuador iOS'],
                'android' => ['app_id' => 'ec.com.fiestacerca', 'nombre' => 'Ecuador Android'],
            ],
        ];

        foreach ($apps as $codigo => $porPlataforma) {
            $pais = Pais::where('codigo', $codigo)->first();
            if (! $pais) {
                continue;
            }
            foreach ($porPlataforma as $plataforma => $datos) {
                AppsflyerApp::updateOrCreate(
                    ['pais_id' => $pais->id, 'plataforma' => $plataforma],
                    $datos
                );
            }
        }
    }
}
