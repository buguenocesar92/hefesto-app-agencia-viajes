<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Agencia;
use App\Models\Pasajero;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pasajeros para las pruebas.
 *
 * `nombre_declarado` puede venir abreviado a proposito: es como el pasajero se
 * escribe a si mismo, y la abreviacion es justamente el caso que produce el
 * problema del boleto.
 */
class PasajeroFactory extends Factory
{
    protected $model = Pasajero::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Agencia::factory(),
            'nombre_declarado' => $this->faker->firstName() . ' ' . $this->faker->lastName(),
            'email' => $this->faker->safeEmail(),
            'telefono' => '+56 9 ' . $this->faker->numerify('#### ####'),
            'fecha_nacimiento' => $this->faker->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'nacionalidad' => 'Chilena',
            'activo' => true,
        ];
    }

    /** Nombre declarado abreviado, como en la venta en linea. */
    public function nombreAbreviado(): static
    {
        return $this->state(fn (array $atributos): array => [
            'nombre_declarado' => explode(' ', (string) $atributos['nombre_declarado'])[0],
        ]);
    }

    /** Un pasajero que ya esta dado de alta. */
    public function existente(): static
    {
        return $this->state(fn (): array => []);
    }

    public function de(Agencia $agencia): static
    {
        return $this->state(fn (): array => ['tenant_id' => $agencia->id]);
    }
}