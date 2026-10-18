<?php

declare(strict_types=1);

namespace App\Services\Viajes;

use App\Enums\EstadoAsiento;
use App\Models\Asiento;
use App\Models\Usuario;
use App\Models\Vuelo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Toma y suelta asientos. ESTE ES EL MODULO DE CONCURRENCIA DEL PRODUCTO.
 *
 * ================================================================
 * POR QUE SE USA UN INDICE DE EXCLUSION Y NO UN CANDADO DE REDIS
 * ================================================================
 * El problema: dos compras simultaneas no pueden tomar el mismo asiento.
 *
 * La solucion obvia es un candado en Redis (`SET NX`). Se evaluo y se
 * descarto COMO GARANTIA, por cuatro razones concretas:
 *
 * 1. SEPARACION ENTRE EL CANDADO Y LA VENTA.
 *    Con un candado en Redis, el candado y el INSERT del asiento viven en
 *    sistemas distintos. Redis no conoce las transacciones de PostgreSQL. Si
 *    el proceso muere entre "tome el candado" y "inserte el asiento", el
 *    candado expira por TTL y el asiento queda libre: pasa. Si el proceso
 *    muere DESPUES de insertar y antes de confirmar, la transaccion se
 *    revierte pero el candado sigue tomado: el asiento queda apretado hasta
 *    que expire el TTL. Ninguno de los dos casos es un conflicto de asientos,
 *    pero los dos son reclamos del cliente.
 *
 * 2. UN TTL ES UNA GARANTIA QUE DEPENDE DEL RELOJ.
 *    Si Redis se reinicia con los datos en disco, o si la red se parte entre
 *    la app y Redis, la respuesta a "este asiento esta tomado" puede ser
 *    "no" cuando es "si". Un vuelo lleno es exactamente el peor escenario.
 *
 * 3. NO SE PUEDE CONSULTAR EL ESTADO SIN ESCRIBIR.
 *    Para mostrar el mapa de asientos en la pantalla hay que responder si cada
 *    asiento esta libre. Con un candado de Redis habria que preguntar a Redis
 *    por cientos de asientos, o mantener un segundo estado en la base que
 *    puede quedar desincronizado. Con el indice de exclusion, el estado esta
 *    en la fila y la pantalla se arma con un `SELECT`.
 *
 * 4. EL INDICE DE EXCLUSION NO TIENE VENTANA.
 *    La exclusion se evalua DENTRO de la transaccion, en el momento del
 *    INSERT o del UPDATE. No existe el intervalo entre "comprobar que esta
 *    libre" y "marcarlo como tomado" porque no hay dos pasos: hay uno. Dos
 *    transacciones que intenten tomar el mismo asiento se serializan solas;
 *    una entra y la otra recibe una violacion de la restriccion.
 *
 * ================================================================
 * LO QUE SE USA, EN CONCRETO
 * ================================================================
 * 1. `asientos_no_duplicados`, un indice de exclusion sobre
 *    `(vuelo_id, numero)` con la condicion `estado IN ('bloqueado',
 *    'asignado')`. Es la garantia.
 *
 * 2. Un candado de Redis por vuelo, para serializar las compras de un mismo
 *    vuelo y reducir la tasa de colisiones. Es una OPTIMIZACION: hace el
 *    sistema mas rapido y mas barato en la base, pero si Redis no esta, la
 *    operacion sigue siendo correcta. Por eso el metodo funciona con y sin
 *    Redis, y por eso el candado nunca decide el resultado: el candado dice
 *    "espera un momento", y la base dice si el asiento se pudo tomar.
 *
 * 3. Una transaccion con `SELECT ... FOR UPDATE` sobre el asiento cuando hace
 *    falta, para que la lectura y la escritura sean coherentes.
 *
 * Ver docs/01, seccion "Bloqueo de asientos", y docs/02, seccion "asientos".
 */
class ServicioAsientos
{
    /** Prefijo de las claves deRedis. */
    private const PREFIJO_CANDADO = 'agencia:asientos:vuelo:';

    /** Prefijo del candado corto que serializa las compras de un vuelo. */
    private const PREFIJO_SERIE = 'agencia:asientos:serie:';

    /**
     * Toma un asiento para una reserva.
     *
     * @param  string  $vueloId  Vuelo con la fecha concreta.
     * @param  string  $numero  Numero de asiento, tal como lo imprime la aerolinea.
     * @param  string  $reservaId  Reserva que lo toma.
     * @param  string|null  $reservaPasajeroId  Pasajero concreto, si ya se sabe.
     * @param  Usuario|null  $usuario  Agente que hace la operacion.
     * @param  bool  $bloquear  Si es true, el asiento queda en estado bloqueado con
     *                          vencimiento; si es false, queda asignado de inmediato.
     *
     * @throws RuntimeException si el asiento no existe o no se puede tomar.
     */
    public function tomar(
        string $vueloId,
        string $numero,
        string $reservaId,
        ?string $reservaPasajeroId = null,
        ?Usuario $usuario = null,
        bool $bloquear = false,
    ): Asiento {
        $ttl = (int) config('viajes.asientos.candado_ttl_segundos');
        $claveSerie = self::PREFIJO_SERIE . $vueloId;

        // El candado de Redis es opcional a proposito. Si Redis no responde,
        // se sigue por la exclusion de PostgreSQL. Ver la clase completa.
        $candado = $this->tomarCandadoDeSerie($claveSerie, $ttl);

        try {
            return DB::transaction(function () use ($vueloId, $numero, $reservaId, $reservaPasajeroId, $usuario, $bloquear, $ttl) {
                $asiento = $this->buscarParaTomar($vueloId, $numero);

                if ($asiento->estado === EstadoAsiento::NoDisponible) {
                    throw new RuntimeException(
                        'El asiento ' . $numero . ' no esta disponible en este vuelo.'
                    );
                }

                if ($asiento->bloqueoVencido()) {
                    // Un bloqueo anterior quedo colgado. Se libera y se sigue:
                    // mientras el estado era "bloqueado" el asiento no estaba
                    // realmente tomado por nadie.
                    $asiento->estado = EstadoAsiento::Disponible;
                    $asiento->bloqueado_hasta = null;
                    $asiento->bloqueado_por_usuario_id = null;
                }

                if ($asiento->ocupa() && $asiento->reserva_id !== $reservaId) {
                    throw new RuntimeException(
                        'El asiento ' . $numero . ' ya esta tomado por otra reserva. '
                        . 'Numero de reserva: ' . ($asiento->reserva_id ?? 'sin dato') . '.'
                    );
                }

                $asiento->reserva_id = $reservaId;
                $asiento->reserva_pasajero_id = $reservaPasajeroId;
                $asiento->estado = $bloquear ? EstadoAsiento::Bloqueado : EstadoAsiento::Asignado;
                $asiento->bloqueado_por_usuario_id = $usuario?->id;
                $asiento->bloqueado_en = now();
                $asiento->bloqueado_hasta = $bloquear ? now()->addSeconds($ttl) : null;

                try {
                    $asiento->save();
                } catch (QueryException $excepcion) {
                    // La violacion del indice de exclusion.
                    //
                    // Aca esta la garantia real. Dos compras simultaneas llegan
                    // las dos a la fila y las dos intentan el UPDATE. PostgreSQL
                    // las serializa por el indice: una gana, la otra recibe
                    // este error. No hay ninguna forma de que las dos salgan
                    // con el mismo asiento.
                    if ($this->esViolacionDeExclusion($excepcion)) {
                        throw new RuntimeException(
                            'El asiento ' . $numero . ' fue tomado por otra compra al mismo tiempo. '
                            . 'Se debe elegir otro asiento.',
                            0,
                            $excepcion
                        );
                    }

                    throw $excepcion;
                }

                return $asiento->refresh();
            });
        } finally {
            $this->soltarCandadoDeSerie($candado);
        }
    }

    /**
     * Libera un asiento: vuelve a `Disponible` y queda el rastro del uso.
     *
     * Liberar NO borra: el estado `Liberado` conserva la evidencia de que ese
     * asiento estuvo tomado, que es lo que sirve cuando alguien pregunta por
     * que se le cambio el asiento.
     */
    public function liberar(string $asientoId, string $motivo = 'Liberado por el agente'): Asiento
    {
        $candado = null;

        try {
            $asiento = Asiento::query()->lockForUpdate()->findOrFail($asientoId);

            $candado = $this->tomarCandadoDeSerie(self::PREFIJO_SERIE . $asiento->vuelo_id, 5);

            $asiento->estado = $asiento->estado === EstadoAsiento::NoDisponible
                ? EstadoAsiento::NoDisponible
                : EstadoAsiento::Liberado;
            $asiento->reserva_id = null;
            $asiento->reserva_pasajero_id = null;
            $asiento->bloqueado_hasta = null;
            $asiento->bloqueado_por_usuario_id = null;
            $asiento->save();

            Log::info('Asiento liberado', [
                'asiento_id' => $asiento->id,
                'motivo' => $motivo,
            ]);

            return $asiento->refresh();
        } finally {
            if ($candado !== null) {
                $this->soltarCandadoDeSerie($candado);
            }
        }
    }

    /**
     * Libera los bloqueos vencidos de un vuelo.
     *
     * Un bloqueo con TTL vencido NO es un asiento tomado: es un asiento que
     * nadie esta mirando. Este metodo existe para que no haya que esperar a que
     * alguien lo pise para liberarlo.
     */
    public function liberarVencidos(string $vueloId): int
    {
        return Asiento::query()
            ->where('vuelo_id', $vueloId)
            ->where('estado', EstadoAsiento::Bloqueado->value)
            ->whereNotNull('bloqueado_hasta')
            ->where('bloqueado_hasta', '<', now())
            ->update([
                'estado' => EstadoAsiento::Disponible->value,
                'bloqueado_hasta' => null,
                'bloqueado_por_usuario_id' => null,
                'reserva_id' => null,
                'reserva_pasajero_id' => null,
            ]);
    }

    /**
     * Mapa de asientos de un vuelo, para pintar la pantalla.
     *
     * Se lee de la fila, no de Redis: lo que se muestra es lo que la base
     * garantiza, no lo que un cache dice.
     *
     * @return list<array{numero: string, clase: string|null, estado: string, etiqueta: string, disponible: bool}>
     */
    public function mapa(string $vueloId): array
    {
        $asientos = Asiento::query()
            ->where('vuelo_id', $vueloId)
            ->orderBy('fila_orden')
            ->orderByRaw("columna_numero NULLS LAST")
            ->get();

        return $asientos->map(fn (Asiento $asiento): array => [
            'numero' => $asiento->numero,
            'clase' => $asiento->clase,
            'estado' => $asiento->estado->value,
            'etiqueta' => $asiento->estado->etiqueta(),
            'disponible' => ! $asiento->ocupa() && $asiento->estado !== EstadoAsiento::NoDisponible,
        ])->values()->all();
    }

    /**
     * El asiento, con la fila bloqueada para escritura.
     *
     * `lockForUpdate` hace que dos compras que editan el mismo asiento se
     * esperen en la base, en vez de leer las dos el mismo estado viejo y
     * decidir las dos que estaba libre.
     */
    private function buscarParaTomar(string $vueloId, string $numero): Asiento
    {
        $asiento = Asiento::query()
            ->where('vuelo_id', $vueloId)
            ->where('numero', $numero)
            ->lockForUpdate()
            ->first();

        if ($asiento === null) {
            throw new RuntimeException(
                'El asiento ' . $numero . ' no existe en este vuelo. '
                . 'Revise el numero: los asientos se identifican tal como los imprime la aerolinea.'
            );
        }

        return $asiento;
    }

    /**
     * Reconoce la violacion del indice de exclusion.
     *
     * PostgreSQL devuelve el codigo `23P01` (`exclusion_violation`). Es el
     * unico codigo que puede venir de este indice, asi que se puede mirar sin
     * arriesgarse a confundirla con otro error.
     */
    private function esViolacionDeExclusion(QueryException $excepcion): bool
    {
        return $excepcion->getCode() === '23P01';
    }

    /**
     * Candado de serie del vuelo, en Redis.
     *
     * Devuelve la CLAVE si se logro tomar el candado, o null si ya lo tiene
     * otro o si Redis no esta disponible.
     *
     * Un null NO es un error: la operacion sigue y la exclusion de PostgreSQL
     * resuelve los conflictos. El valor escrito es fijo porque la clave ya
     * identifica al vuelo; no hace falta un propietario unico.
     */
    private function tomarCandadoDeSerie(string $clave, int $ttl): ?string
    {
        try {
            // add() devuelve true si lo agarro, false si ya estaba tomado.
            if (Cache::store('redis')->add($clave, 'en-uso', $ttl)) {
                return $clave;
            }

            // Alguien mas esta comprando en este vuelo. Se espera un poco y se
            // reintenta, sin pasarse del TTL.
            for ($intento = 0; $intento < 5; $intento++) {
                usleep(200_000);

                if (Cache::store('redis')->add($clave, 'en-uso', $ttl)) {
                    return $clave;
                }
            }

            return null;
        } catch (\Throwable $excepcion) {
            Log::warning('Redis no respondio al pedir el candado de asientos; se sigue sin el', [
                'clave' => $clave,
                'error' => $excepcion->getMessage(),
            ]);

            return null;
        }
    }

    private function soltarCandadoDeSerie(?string $clave): void
    {
        if ($clave === null) {
            return;
        }

        try {
            Cache::store('redis')->forget($clave);
        } catch (\Throwable) {
            // Si no se puede soltar, el TTL lo libera solo. No es grave: el
            // candado es una optimizacion, no la garantia.
        }
    }
}