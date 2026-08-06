<?php

namespace Database\Seeders;

use App\Models\Pais;
use Illuminate\Database\Seeder;

class PaisSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $paises = [
            ['codigo' => 'MX', 'nombre' => 'México'],
            ['codigo' => 'PE', 'nombre' => 'Perú'],
            ['codigo' => 'EC', 'nombre' => 'Ecuador'],
            ['codigo' => 'PA', 'nombre' => 'Panamá'],
        ];

        foreach ($paises as $pais) {
            Pais::updateOrCreate(['codigo' => $pais['codigo']], $pais);
        }
    }
}
