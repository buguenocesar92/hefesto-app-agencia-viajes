<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Contexto de agencia (tenant) de la peticion.
 *
 * La RLS de PostgreSQL lee la agencia desde la variable de sesion
 * `app.tenant_id`. Esta clase es la UNICA que la escribe.
 *
 * ALCANCE DE TRANSACCION, Y POR QUE NO ES NEGOCIABLE
 * --------------------------------------------------
 * Se usa `set_config(..., true)`, es decir alcance de TRANSACCION: al
 * confirmar o revertir, PostgreSQL descarta el valor solo.
 *
 * Con un `SET` normal (alcance de sesion) el valor sobrevive a la peticion. Y
 * el worker de colas REUTILIZA la misma conexion para trabajos de agencias
 * distintas: un trabajo de la agencia "Andes Travel" podria leer los datos de
 * "Viajes Sur" si el trabajo anterior dejo el contexto colgado. Ese es
 * exactamente el fallo que este diseno evita, y por eso `establecer()`
 * EXIGE estar dentro de una transaccion y lanza excepcion si no lo esta.
 *
 * Mismo patron que el ya documentado en
 * apps/portal-autoservicio/docs/03-MULTITENANCY.md. Ver docs/03.
 */
class ContextoTenant
{
    /** Variable de sesion de PostgreSQL que lee la politica RLS. */
    public const VARIABLE = 'app.tenant_id';

    /**
     * Fija la agencia de la peticion en la conexion actual.
     *
     * @throws RuntimeException si no hay una transaccion abierta.
     */
    public function establecer(string $tenantId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'No se puede fijar el contexto de agencia fuera de una transaccion. '
                . 'Use App\Services\Tenancy\ContextoTenant::ejecutarAislado().'
            );
        }

        DB::select('SELECT set_config(?, ?, true)', [self::VARIABLE, $tenantId]);
    }

    /**
     * Ejecuta una operacion dentro de una transaccion con la agencia fija.
     * Al salir, PostgreSQL descarta el valor de la variable.
     *
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    public function ejecutarAislado(string $tenantId, Closure $operacion): mixed
    {
        return DB::transaction(function () use ($tenantId, $operacion) {
            $this->establecer($tenantId);

            return $operacion();
        });
    }

    /**
     * Lee la variable de sesion sin pasar por `public.app_tenant_id()`, que
     * lanza excepcion cuando no hay contexto. Sirve para diagnostico: si
     * devuelve null, ninguna politica RLS de la base va a devolver filas.
     */
    public function tenantIdEnConexion(): ?string
    {
        $fila = DB::selectOne('SELECT current_setting(?, true) AS valor', [self::VARIABLE]);

        $valor = $fila->valor ?? null;

        return $valor === null || $valor === '' ? null : $valor;
    }

    /** Identificador de la agencia autenticada, si lo hay. */
    public function tenantIdSolicitado(): ?string
    {
        return auth()->user()?->tenant_id;
    }
}