<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Proveedores para las pruebas.
 */
class ProveedorFactory extends Factory
{
    protected $model = Proveedor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => \App\Models\Agencia::factory(),
            'tipo' => 'aerolinea',
            'codigo' => $this->faker->unique()->lexify('??'),
            'nombre' => $this->faker->company() . ' Aerolineas',
            'activo' => true,
        ];
    }
}