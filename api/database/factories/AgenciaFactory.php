<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Agencia;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Agencias para las pruebas.
 *
 * `dominio_correo` es lo que decide a que agencia pertenece una persona al
 * entrar, asi que las pruebas de aislamiento usan dominios distintos.
 */
class AgenciaFactory extends Factory
{
    protected $model = Agencia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = $this->faker->company() . ' Viajes';

        return [
            'slug' => Str::slug($nombre) . '-' . $this->faker->unique()->numerify('###'),
            'nombre' => $nombre,
            'razon_social' => $nombre . ' Ltda.',
            'rut' => $this->faker->numerify('##.###.###-#'),
            'dominio_correo' => $this->faker->unique()->domainName(),
            'activo' => true,
        ];
    }

    public function inactiva(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}