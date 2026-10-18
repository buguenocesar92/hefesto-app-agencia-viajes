<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Asiento;
use App\Models\Pasajero;
use App\Models\Pago;
use App\Models\Reserva;
use App\Models\Tarifa;
use App\Models\Usuario;
use App\Models\Vuelo;
use App\Policies\AdministracionPolicy;
use App\Policies\AsientoPolicy;
use App\Policies\PasajeroPolicy;
use App\Policies\ReservaPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registro de Policies.
 *
 * Se registran de forma explicita y no por convencion, para que quede a la
 * vista que Vuelo tiene `AdministracionPolicy` y no una Policy propia: un
 * vuelo se ve como cualquier agente, pero solo el administrador lo modifica.
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private array $policies = [
        Reserva::class => ReservaPolicy::class,
        Pasajero::class => PasajeroPolicy::class,
        Vuelo::class => AsientoPolicy::class,
        Asiento::class => AsientoPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $modelo => $policy) {
            Gate::policy($modelo, $policy);
        }

        // Administracion: proveedores, productos, tarifas y politicas.
        Gate::define('administrar', [AdministracionPolicy::class, 'actualizar']);
        Gate::define('cambiar-politicas', [AdministracionPolicy::class, 'cambiarPoliticas']);
        Gate::define('ver-administracion', [AdministracionPolicy::class, 'ver']);
    }
}