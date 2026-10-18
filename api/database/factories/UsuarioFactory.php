<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Agencia;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Agentes para las pruebas.
 *
 * `subjekt_authentik` simula el `sub` del token de Authentik. Es lo unico que
 * conecta la identidad externa con la base: el rol y los permisos salen de
 * esta tabla, no del proveedor.
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Agencia::factory(),
            'subjekt_authentik' => (string) $this->faker->unique()->uuid(),
            'nombre' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'rol' => 'agente',
            'activo' => true,
            'ultimo_acceso' => null,
        ];
    }

    public function administrador(): static
    {
        return $this->state(fn (): array => ['rol' => 'administrador']);
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }

    public function de(Agencia $agencia): static
    {
        return $this->state(fn (): array => ['tenant_id' => $agencia->id]);
    }
}