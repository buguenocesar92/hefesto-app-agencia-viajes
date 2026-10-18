<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\AuditorDeAcceso;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anota en la bitacora las peticiones que tocan datos personales.
 *
 * Corre DESPUES de `ResolverTenant`, porque la bitacora guarda el
 * `tenant_id` y necesita que el contexto este disponible.
 *
 * NO se registra el cuerpo de la peticion ni el valor del documento: se
 * registra QUE se pidio y quien lo pidio. Meter el dato sensible en el
 * registro de auditoría sería crear un segundo lugar donde vive el dato, sin
 * los controles del primero. Ver docs/07.
 */
class AuditarAcceso
{
    /** Rutas cuyo acceso se anota, con la accion de la bitacora. */
    private const RUTAS_AUDITADAS = [
        'api/v1/pasajeros/*/documento' => 'documento.leido',
        'api/v1/documentos/*' => 'documento.leido',
        'api/v1/reservas' => 'reserva.creada',
        'api/v1/pagos' => 'pago.registrado',
        'api/v1/asientos/*/bloquear' => 'asiento.bloqueado',
    ];

    public function __construct(private readonly AuditorDeAcceso $auditor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        $accion = $this->accionPara($request);

        if ($accion !== null && $respuesta->getStatusCode() < 400) {
            $this->auditor->registrar(
                $accion,
                $this->recursoPara($request),
                $this->recursoIdPara($request),
                // El motivo lo manda el agente en la cabecera `X-Motivo-Consulta`.
                // Es obligatorio cuando la accion expone el documento entero.
                $this->motivo($request),
            );
        }

        return $respuesta;
    }

    private function accionPara(Request $request): ?string
    {
        foreach (self::RUTAS_AUDITADAS as $patron => $accion) {
            if ($request->is($patron)) {
                return $accion;
            }
        }

        return null;
    }

    private function recursoPara(Request $request): string
    {
        $recurso = (string) $request->segment(3);

        return $recurso === '' ? 'desconocido' : $recurso;
    }

    private function recursoIdPara(Request $request): ?string
    {
        $segmentos = $request->segments();

        // /api/v1/<recurso>/<id>/<accion> -> el id es el cuarto segmento.
        return isset($segmentos[4]) ? $segmentos[4] : null;
    }

    private function motivo(Request $request): ?string
    {
        $motivo = $request->header('X-Motivo-Consulta');

        if ($motivo === null || trim($motivo) === '') {
            return null;
        }

        return substr(trim($motivo), 0, 255);
    }
}