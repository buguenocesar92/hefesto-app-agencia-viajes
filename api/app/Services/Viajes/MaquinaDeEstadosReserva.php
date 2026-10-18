<?php

declare(strict_types=1);

namespace App\Services\Viajes;

use App\Enums\EstadoReserva;
use App\Models\Reserva;
use App\Models\ReservaEvento;
use App\Models\ReservaPasajero;
use App\Models\Usuario;
use App\Services\Tenancy\AuditorDeAcceso;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Maquina de estados de la reserva.
 *
 * UNA RESERVA NO SE CANCELA BORRANDOLA: PASA POR ESTADOS
 * ------------------------------------------------------
 * Borrar una reserva destruye el historial de la agencia. Queda sin saber que
 * hubo una venta, quien la hizo, cuanto se cobro ni con que costo se devolvio.
 *
 * Por eso todo cambio de estado pasa por aqui, y cada cambio deja una fila en
 * `reserva_eventos`, DENTRO de la misma transaccion que el cambio. Si el
 * historial no se puede escribir, el cambio tampoco: un cambio de estado sin
 * rastro es peor que un cambio que no ocurrio.
 */
class MaquinaDeEstadosReserva
{
    public function __construct(
        private readonly CalculadoraCancelacion $calculadora,
        private readonly AuditorDeAcceso $auditor,
    ) {}

    /**
     * Mueve la reserva a un estado nuevo, dejando rastro.
     *
     * @throws RuntimeException si la transicion no esta permitida.
     */
    public function transicionar(
        Reserva $reserva,
        EstadoReserva $destino,
        ?Usuario $usuario = null,
        ?string $motivo = null,
    ): Reserva {
        $origen = $reserva->estado;

        if (! $origen->puedeTransicionarA($destino)) {
            throw new RuntimeException(sprintf(
                'No se puede pasar de "%s" a "%s". Las transiciones permitidas son: %s.',
                $origen->etiqueta(),
                $destino->etiqueta(),
                $this->textoTransiciones($origen),
            ));
        }

        return DB::transaction(function () use ($reserva, $origen, $destino, $usuario, $motivo) {
            // La fila se relee con bloqueo: entre que el agente abrio la pantalla
            // y que confirmo, otro agente pudo moverla. Sin esto, el segundo
            // guarda sobre un estado viejo y el historial queda incoherente.
            $bloqueada = Reserva::query()->lockForUpdate()->findOrFail($reserva->id);

            if ($bloqueada->estado !== $origen) {
                throw new RuntimeException(
                    'La reserva cambio de estado mientras se editaba ('
                    . $bloqueada->estado->etiqueta() . '). Vuelva a cargarla.'
                );
            }

            $costoCentimos = 0;

            if ($destino === EstadoReserva::Cancelada) {
                $costo = $this->calculadora->calcular($bloqueada);

                $costoCentimos = $costo['costo_centimos'];

                $bloqueada->cancelada_en = now();
                $bloqueada->costo_cancelacion_centimos = $costoCentimos;
                $bloqueada->motivo_cancelacion = $motivo ?? $costo['explicacion'];
            }

            if ($destino === EstadoReserva::Emitida) {
                $this->verificarNombresParaEmitir($bloqueada);

                $bloqueada->emitida_en = now();
            }

            if ($destino === EstadoReserva::Usada) {
                $bloqueada->usada_en = now();
            }

            $bloqueada->estado = $destino;
            $bloqueada->save();

            ReservaEvento::query()->create([
                'tenant_id' => $bloqueada->tenant_id,
                'reserva_id' => $bloqueada->id,
                'estado_anterior' => $origen,
                'estado_nuevo' => $destino,
                'motivo' => $motivo,
                'costo_centimos' => $costoCentimos,
                'usuario_id' => $usuario?->id,
                'creado_en' => now(),
            ]);

            $this->auditor->registrar(
                $destino === EstadoReserva::Cancelada ? 'reserva.cancelada' : 'reserva.estado_cambiado',
                'reserva',
                $bloqueada->id,
                $motivo,
                ['de' => $origen->value, 'a' => $destino->value],
            );

            return $bloqueada->refresh();
        });
    }

    /**
     * Emitir exige que TODOS los nombres de la reserva esten verificados.
     *
     * Este es el control que evita el problema con el que nacio el producto.
     * No basta con que el nombre se haya armado desde el documento: tiene que
     * haberlo revisado una persona. El sistema arma el nombre y avisa; el
     * agente decide.
     *
     * @throws RuntimeException si hay pasajeros con el nombre sin verificar.
     */
    private function verificarNombresParaEmitir(Reserva $reserva): void
    {
        $sinVerificar = ReservaPasajero::query()
            ->where('reserva_id', $reserva->id)
            ->where('estado_verificacion_nombre', '!=', \App\Enums\EstadoVerificacionNombre::Verificado->value)
            ->get();

        if ($sinVerificar->isEmpty()) {
            return;
        }

        $nombres = $sinVerificar
            ->map(static fn (ReservaPasajero $pasajero): string => $pasajero->nombre_boleto . ' (' . $pasajero->estado_verificacion_nombre->etiqueta() . ')')
            ->implode(', ');

        throw new RuntimeException(
            'No se puede emitir la reserva: hay pasajeros con el nombre del boleto sin verificar. '
            . 'Pasajeros: ' . $nombres . '. '
            . 'La correspondencia entre el nombre del boleto y el documento la revisa un agente.'
        );
    }

    /**
     * Registra la verificacion del nombre de un pasajero en una reserva.
     *
     * Aca es donde un agente confirma (o rechaza) que el nombre del boleto
     * coincide con el documento. Se guarda quien, cuando y que observo.
     */
    public function verificarNombre(
        ReservaPasajero $pasajeroReserva,
        \App\Enums\EstadoVerificacionNombre $estado,
        Usuario $usuario,
        ?string $observacion = null,
    ): ReservaPasajero {
        if (! in_array($estado, [
            \App\Enums\EstadoVerificacionNombre::Verificado,
            \App\Enums\EstadoVerificacionNombre::Rechazado,
        ], true)) {
            throw new RuntimeException(
                'Un agente solo puede marcar un nombre como verificado o rechazado, no como pendiente.'
            );
        }

        $pasajeroReserva->estado_verificacion_nombre = $estado;
        $pasajeroReserva->verificado_por = $usuario->id;
        $pasajeroReserva->verificado_en = now();
        $pasajeroReserva->observacion_verificacion = $observacion;
        $pasajeroReserva->save();

        $this->auditor->registrar(
            'documento.leido',
            'reserva_pasajero',
            $pasajeroReserva->id,
            $observacion,
            ['estado' => $estado->value],
        );

        Log::info('Nombre de boleto verificado por un agente', [
            'reserva_pasajero_id' => $pasajeroReserva->id,
            'estado' => $estado->value,
            'usuario_id' => $usuario->id,
        ]);

        return $pasajeroReserva->refresh();
    }

    private function textoTransiciones(EstadoReserva $estado): string
    {
        $destinos = $estado->transicionesPermitidas();

        if ($destinos === []) {
            return 'ninguna: es un estado terminal';
        }

        return implode(', ', array_map(
            static fn (EstadoReserva $destino): string => $destino->etiqueta(),
            $destinos
        ));
    }
}