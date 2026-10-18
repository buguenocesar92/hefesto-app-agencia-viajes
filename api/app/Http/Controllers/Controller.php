<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Controlador base.
 *
 * Solo trae `authorize()` y el trait de autorizacion de Laravel. No hay logica
 * de tenancy aqui: el contexto de agencia lo fija
 * App\Http\Middleware\ResolverTenant, que corre antes que cualquier
 * controlador.
 */
abstract class Controller
{
    use AuthorizesRequests;
}