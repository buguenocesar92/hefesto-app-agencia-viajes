<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Proveedor de servicios de la aplicacion.
 *
 * El punto de un solo proveedor es que se vea que no hay nada raro
 * registrando: la autorizacion esta en AuthServiceProvider y el resto se
 * resuelve por autobind de Laravel.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}