<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoReserva;
use App\Models\Producto;
use App\Models\Reserva;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Reservas para las pruebas.
 *
 * El estado por defecto es `pendiente`: es el estado en el que nacen TODAS las
 * reservas, y el que mas se prueba, porque es donde se decide el nombre del
 * boleto.
 */
class ReservaFactory extends Factory
{
    protected $model = Reserva::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Producto::factory(),
            'folio' => 'V-' . $this->faker->unique()->numerify('######'),
            'producto_id' => Producto::factory(),
            'vuelo_id' => null,
            'tarifa_id' => null,
            'proveedor_id' => null,
            'estado' => EstadoReserva::Pendiente,
            'origen' => 'mostrador',
            // Centimos, no pesos. Un ticket de vuelo no lleva decimales.
            'total_centimos' => 4500000,
            'emitida_en' => null,
            'usada_en' => null,
            'cancelada_en' => null,
            'motivo_cancelacion' => null,
            'costo_cancelacion_centimos' => 0,
            'creada_por' => Usuario::factory(),
            'creado_en' => now(),
        ];
    }

    public function confirmada(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoReserva::Confirmada]);
    }

    public function emitida(): static
    {
        return $this->state(fn (): array => [
            'estado' => EstadoReserva::Emitida,
            'emitida_en' => now(),
        ]);
    }

    public function cancelada(): static
    {
        return $this->state(fn (): array => [
            'estado' => EstadoReserva::Cancelada,
            'cancelada_en' => now(),
        ]);
    }

    public function deTotal(int $centimos): static
    {
        return $this->state(fn (): array => ['total_centimos' => $centimos]);
    }
}