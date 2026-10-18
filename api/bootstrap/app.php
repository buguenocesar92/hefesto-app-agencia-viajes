<?php

declare(strict_types=1);

use App\Http\Middleware\AuditarAcceso;
use App\Http\Middleware\ResolverTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nucleo de la aplicacion.
 *
 * ORDEN DE LOS MIDDLEWARE, Y POR QUE IMPORTA
 * -----------------------------------------
 * `ResolverTenant` va PRIMERO, antes que `auth` y antes que cualquier otra
 * cosa. La RLS de PostgreSQL lee el tenant desde la variable de sesion
 * `app.tenant_id`, y lo unico que puede fijarla de forma segura es el
 * middleware, dentro de una transaccion. Si `ResolverTenant` corriera despues
 * de un middleware que ya consulto la base, ese middleware leeria sin
 * contexto: `public.app_tenant_id()` lanzaria excepcion y la peticion
 * fallaria. Falla cerrado, que es lo que queremos, pero es un fallo por
 * descuido. Va primero para que no ocurra.
 *
 * `AuditarAcceso` va DESPUES de `ResolverTenant`, porque la bitacora guarda el
 * `tenant_id` y necesita que el contexto este disponible.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__ . '/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            ResolverTenant::class,
            AuditarAcceso::class,
        ]);

        $middleware->web(prepend: [
            ResolverTenant::class,
        ]);

        // El grupo `api` no lleva CSRF porque no usa cookies de sesion: la
        // sesion viaja en un token portador (Sanctum) o la peticion viene de
        // nuestro propio servidor Nuxt por el backend-for-frontend. El CSRF
        // que si importa, el del formulario web con cookie de sesion, lo
        // aplica el grupo `web` de Laravel. Ver docs/07, seccion "CSRF".
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Un error de RLS (falta de contexto de tenant) no se muestra tal
        // cual al cliente: podria filtrar detalles del esquema. Se registra
        // completo y se responde con un error generico.
        $exceptions->render(function (\Illuminate\Database\QueryException $excepcion, Request $peticion) {
            if (! str_contains($excepcion->getMessage(), 'app_tenant_id')) {
                return null;
            }

            report($excepcion);

            if ($peticion->expectsJson()) {
                return response()->json([
                    'mensaje' => 'La operacion no pudo completarse por un problema de contexto de agencia.',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return response('La operacion no pudo completarse por un problema de contexto de agencia.', Response::HTTP_INTERNAL_SERVER_ERROR);
        });
    })
    ->create();

/*
 * Nota sobre `set_config(..., true)`:
 * la variable de contexto se fija con alcance de TRANSACCION dentro de
 * ResolverTenant, no aqui. Si se fijara con un `SET` de alcance de sesion,
 * sobrevivia a la peticion y el worker de colas, que reutiliza la misma
 * conexion, podria arrastrar el contexto de una agencia a otra. Ver docs/03.
 */