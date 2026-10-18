<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada de la semilla.
 *
 * Solo corre la agencia de ejemplo. No se siembran datos de faker sueltos: una
 * base llena de datos inventados no sirve para probar nada, porque nadie sabe
 * que deberia haber ahi.
 *
 * Para correrla:
 *   php artisan migrate --seed
 *
 * OJO: la semilla escribe datos personales de ejemplo. Son ficticios, pero
 * estan en una tabla con el mismo cuidado que los reales. Ver docs/07.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AgenciaEjemploSeeder::class);
    }
}