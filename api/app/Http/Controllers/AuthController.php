<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Agencia;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrada y salida: Authentik como proveedor de identidad.
 *
 * POR QUE EL ROL NO VIENE DE AUTHENTIK
 * ------------------------------------
 * Authentik responde "quien entro". La tabla `usuarios` responde "que puede
 * hacer dentro de la agencia". Si el rol viniera del proveedor, cambiar los
 * permisos de un agente seria cambiarlo en un sistema de identidad compartido
 * con otras aplicaciones, y auditarlo seria imposible.
 *
 * Por eso, en el callback se busca el `sub` en `usuarios`. Si no esta, la
 * persona entra pero NO PUEDE OPERAR: es una cuenta que existe en Authentik y
 * que esta agencia no ha dado de alta.
 */
class AuthController extends Controller
{
    /** Propiedades transitorias que manda el proveedor antes del callback. */
    private const CLAVE_PENDIENTE = 'agencia.pendiente.oauth';

    public function __construct(private readonly ContextoTenant $contexto) {}

    /**
     * Arranca el login.
     */
    public function redirigir(): RedirectResponse
    {
        $url = config('services.authentik.redirect');

        return redirect()->away($url);
    }

    /**
     * Vuelve del proveedor de identidad.
     *
     * Busca el agente por su `subjekt_authentik` dentro de una transaccion con
     * el contexto de la agencia ya puesto: sin el, la RLS no dejaria ni leer la
     * fila ni confirmar que la agencia este activa.
     */
    public function callback(): JsonResponse|RedirectResponse
    {
        $subjet = (string) request()->query('sub', '');

        if ($subjet === '') {
            return $this->rechazar('El proveedor de identidad no devolvio un identificador de usuario.');
        }

        // La agencia se deduce del dominio de correo del claim `email`.
        // Si el proveedor no lo manda, no se puede deducir y el ingreso se
        // rechaza: no se busca "la primera agencia".
        $agencia = $this->agenciaDelDominio((string) request()->query('email', ''));

        if ($agencia === null) {
            return $this->rechazar(
                'El correo no pertenece a ninguna agencia registrada en el sistema.'
            );
        }

        $usuario = $this->contexto->ejecutarAislado($agencia->id, function () use ($subjet) {
            return Usuario::query()->where('subjekt_authentik', $subjet)->first();
        });

        if ($usuario === null) {
            return $this->rechazar(
                'Su cuenta esta en el proveedor de identidad pero no esta dada de alta como agente '
                . 'de ninguna agencia. Pida al administrador que la agregue.'
            );
        }

        if (! $usuario->activo) {
            return $this->rechazar('Su cuenta de agente esta desactivada.');
        }

        $usuario->forceFill(['ultimo_acceso' => now()])->saveQuietly();

        // La sesion se abre DENTRO de una transaccion con el contexto puesto:
        // la cookie se emite ahora y las peticiones siguientes tendran su
        // propia transaccion con su propio contexto.
        $peticion = request();
        $peticion->session()->regenerate();
        auth()->guard('web')->login($usuario);

        return response()->json([
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'email' => $usuario->email,
                'rol' => $usuario->rol,
                'agencia' => $agencia->nombre,
            ],
            'mensaje' => 'Ingreso correcto.',
        ]);
    }

    /**
     * Cierre de sesion.
     *
     * Es un POST y no un GET: un GET se podria disparar desde otro sitio con
     * una imagen y cerrarle la sesion a alguien. Ver docs/07.
     */
    public function salir(): JsonResponse
    {
        auth()->guard('web')->logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return response()->json(['mensaje' => 'Sesion cerrada.']);
    }

    /**
     * La agencia a la que pertenece un correo, por su dominio.
     *
     * Lee `agencias` crudo, que NO tiene RLS, y por eso es el unico lugar
     * donde eso es aceptable: todavia no hay sesion. En desarrollo y mientras la
     * sesion no existe, este es el punto de entrada.
     */
    private function agenciaDelDominio(string $email): ?Agencia
    {
        $posicionArroba = strrpos($email, '@');

        if ($posicionArroba === false) {
            return null;
        }

        $dominio = strtolower(substr($email, $posicionArroba + 1));

        if ($dominio === '') {
            return null;
        }

        return DB::table('agencias')
            ->where('dominio_correo', $dominio)
            ->where('activo', true)
            ->first();
    }

    private function rechazar(string $mensaje): JsonResponse
    {
        Log::notice('Ingreso rechazado', ['motivo' => $mensaje]);

        return response()->json(['mensaje' => $mensaje], Response::HTTP_FORBIDDEN);
    }
}