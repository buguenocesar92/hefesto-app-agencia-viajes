<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\BitacoraAcceso;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bitacora de acceso a datos personales.
 *
 * LA LEY 19.628
 * -------------
 * Un pasajero es una persona natural, y su documento de identidad es dato
 * personal. La ley no prohibe tratarlo: prohibe hacerlo sin saber quien lo
 * esta mirando y sin poder demostrar despues quien lo miro.
 *
 * Por eso la bitacora no es un registro de errores ni de rendimiento: es un
 * registro de ACCESOS. Cuando un agente pide ver el documento completo de un
 * pasajero, queda anotado quien, cuando, desde donde y por que motivo. Si el
 * pasajero pregunta "quien miro mis datos", existe una respuesta.
 *
 * QUE SE REGISTRA Y QUE NO
 * ------------------------
 * Se registra la LECTURA del documento, no solo su modificacion. Ver el
 * documento es el acceso sensible, no cambiarlo: el dato no cambia, pero se
 * lee.
 */
class AuditorDeAcceso
{
    /**
     * Anota un acceso a datos personales.
     *
     * @param  string  $accion  Una de las acciones de config/seguridad.php.
     * @param  string  $recurso  Tabla o recurso logico afectado ("pasajero", "documento_viaje"...).
     * @param  string|null  $recursoId  Identificador del registro, si aplica.
     * @param  string|null  $motivo  Por que se pidio el dato. Obligatorio cuando
     *                               la accion es leer el documento completo.
     */
    public function registrar(
        string $accion,
        string $recurso,
        ?string $recursoId = null,
        ?string $motivo = null,
        array $detalle = [],
    ): void {
        $usuario = auth()->user();

        $registro = [
            'tenant_id' => $usuario?->tenant_id ?? $this->tenantIdEnConexion(),
            'usuario_id' => $usuario?->id,
            'accion' => $accion,
            'recurso' => $recurso,
            'recurso_id' => $recursoId,
            'motivo' => $motivo,
            // Se guarda la IP y el agente de navegador: sin eso, un registro
            // de acceso no sirve para responder por el acceso.
            'ip' => request()->ip(),
            'agente' => substr((string) request()->userAgent(), 0, 255),
            'detalle' => $detalle === [] ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE),
            'creado_en' => now(),
        ];

        /*
         | La bitacora se escribe con la conexion principal. Es intencional:
         | la escritura ocurre DENTRO de la peticion, con el contexto ya
         | fijado por ResolverTenant, asi que la politica WITH CHECK acepta la
         | fila. Si la escritura fallara, el acceso ya ocurrio y no se puede
         | permitir que el error se trague el rastro: se escribe al log del
         | servidor, que es independiente, y el acceso sigue adelante.
         */
        try {
            DB::table('bitacora_accesos')->insert($registro);
        } catch (\Throwable $excepcion) {
            Log::error('No se pudo escribir la bitacora de accesos', [
                'accion' => $accion,
                'recurso' => $recurso,
                'recurso_id' => $recursoId,
                'error' => $excepcion->getMessage(),
            ]);
        }
    }

    /**
     * Purga los registros mas antiguos que el periodo de retencion.
     *
     * La retencion es una obligacion, no una cortesia: la Ley 19.628 pide que
     * los datos no se guarden mas alla del plazo que declara la propia
     * organizacion. Por eso el periodo esta en config/tenancy.php y no esta
     * escondido en el codigo.
     */
    public function purgarAntiguos(): int
    {
        $dias = (int) config('tenancy.bitacora_retencion_dias');

        if ($dias <= 0) {
            return 0;
        }

        $contexto = new ContextoTenant();

        // La purga corre por agencia: cada peticion borra lo suyo, dentro de
        // una transaccion con el contexto fijado. No hace falta un comando
        // global con privilegios especiales.
        return $contexto->ejecutarAislado(
            (string) auth()->user()?->tenant_id,
            fn (): int => BitacoraAcceso::query()
                ->where('creado_en', '<', now()->subDays($dias))
                ->delete()
        );
    }

    private function tenantIdEnConexion(): ?string
    {
        try {
            $fila = DB::selectOne('SELECT current_setting(?, true) AS valor', [ContextoTenant::VARIABLE]);

            $valor = $fila->valor ?? null;

            return $valor === null || $valor === '' ? null : $valor;
        } catch (\Throwable) {
            return null;
        }
    }
}