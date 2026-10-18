<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\ContextoTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la agencia de la peticion para que la RLS de PostgreSQL sepa de quien
 * es cada fila.
 *
 * Se aplica PRIMERO, antes que `auth` de la ruta (ver bootstrap/app.php). Por
 * eso no puede usar `$request->user()` todavia: resuelve el agente por su
 * cuenta con el guard `web`, que ya sabe leer la sesion.
 *
 * Peticiones sin sesion (login, callback de Authentik, salud) pasan sin
 * transaccion. Si esas rutas tocaran una tabla protegida,
 * `public.app_tenant_id()` lanzaria excepcion y la peticion fallaria: el
 * aislamiento falla cerrado, nunca abierto.
 */
class ResolverTenant
{
    public function __construct(private readonly ContextoTenant $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = auth()->guard('web')->user();

        if ($usuario === null) {
            return $next($request);
        }

        if ($usuario->tenant_id === null) {
            // Un agente sin agencia no puede operar. Se corta aqui, antes de
            // tocar la base de datos.
            abort(403, 'La cuenta no esta asociada a ninguna agencia.');
        }

        return $this->contexto->ejecutarAislado(
            $usuario->tenant_id,
            static fn () => $next($request)
        );
    }
}