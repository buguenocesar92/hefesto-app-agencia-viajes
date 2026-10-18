<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Producto;
use App\Models\Vuelo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fechas de vuelo para las pruebas.
 *
 * `salida_en` esta en el futuro por defecto: casi todo lo que se prueba con
 * asientos y cancelaciones necesita un vuelo que todavia no salio.
 */
class VueloFactory extends Factory
{
    protected $model = Vuelo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $salida = now()->addDays(20)->setTime(9, 30);

        return [
            'tenant_id' => Producto::factory(),
            'producto_id' => Producto::factory(),
            'codigo_vuelo' => $this->faker->unique()->lexify('??###'),
            'origen' => 'SCL',
            'destino' => $this->faker->randomElement(['SPA', 'EZE', 'LIM', 'MVD']),
            'salida_en' => $salida,
            'llegada_en' => $salida->copy()->addHours(4),
            'aeronave' => 'A320neo',
            'activo' => true,
        ];
    }

    /** Un vuelo que sale en X horas: sirve para probar cancelaciones. */
    public function saleEn(int $horas): static
    {
        return $this->state(fn (): array => [
            'salida_en' => now()->addHours($horas),
            'llegada_en' => now()->addHours($horas)->addHours(4),
        ]);
    }

    public function de(Producto $producto): static
    {
        return $this->state(fn (): array => [
            'producto_id' => $producto->id,
            'tenant_id' => $producto->tenant_id,
        ]);
    }
}