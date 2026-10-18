<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoAsiento;
use App\Models\Asiento;
use App\Models\Vuelo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Asientos para las pruebas y la semilla.
 *
 * `numero` se construye con `fila_orden` y la letra, para que el mapa se pueda
 * ordenar bien. En el servicio de asientos el numero es case-sensitive: "14A"
 * no es "14a".
 */
class AsientoFactory extends Factory
{
    protected $model = Asiento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fila = $this->faker->numberBetween(1, 30);
        $columna = $this->faker->randomElement(['A', 'B', 'C', 'D', 'E', 'F']);

        return [
            'tenant_id' => Vuelo::factory(),
            'vuelo_id' => Vuelo::factory(),
            'numero' => $fila . $columna,
            'clase' => 'Economica',
            'estado' => EstadoAsiento::Disponible,
            'fila_orden' => $fila,
            'columna_numero' => array_search($columna, ['A', 'B', 'C', 'D', 'E', 'F'], true) + 1,
            'reserva_id' => null,
            'reserva_pasajero_id' => null,
            'bloqueado_por_usuario_id' => null,
            'bloqueado_hasta' => null,
            'bloqueado_en' => null,
        ];
    }

    public function asignado(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoAsiento::Asignado]);
    }

    public function bloqueado(): static
    {
        return $this->state(fn (): array => [
            'estado' => EstadoAsiento::Bloqueado,
            'bloqueado_en' => now(),
            'bloqueado_hasta' => now()->addMinutes(15),
        ]);
    }

    public function noDisponible(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoAsiento::NoDisponible]);
    }
}