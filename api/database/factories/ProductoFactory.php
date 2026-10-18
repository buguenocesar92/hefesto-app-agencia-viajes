<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoProducto;
use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Productos del catalogo para las pruebas.
 */
class ProductoFactory extends Factory
{
    protected $model = Producto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Proveedor::factory(),
            'proveedor_id' => Proveedor::factory(),
            'tipo' => TipoProducto::Vuelo,
            'codigo' => $this->faker->unique()->bothify('PRD-####'),
            'nombre' => $this->faker->city() . ' - ' . $this->faker->city(),
            'descripcion' => null,
            'activo' => true,
        ];
    }

    public function vuelo(): static
    {
        return $this->state(fn (): array => ['tipo' => TipoProducto::Vuelo]);
    }

    public function hotel(): static
    {
        return $this->state(fn (): array => ['tipo' => TipoProducto::Hotel]);
    }

    public function paquete(): static
    {
        return $this->state(fn (): array => ['tipo' => TipoProducto::Paquete]);
    }
}