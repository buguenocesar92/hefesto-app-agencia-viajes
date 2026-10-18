<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estados por los que pasa una reserva.
 *
 * UNA RESERVA NO SE BORRA: PASA POR ESTADOS.
 * --------------------------------------------
 * Borrar una reserva destruye el historial de la agencia: deja de constar que
 * hubo una venta, quien la hizo, cuanto se pago y que paso con el pasaje. En
 * una agencia eso no es un detalle tecnico, es la memoria comercial.
 *
 * Por eso no existe `delete()` en el modelo `Reserva`. Para deshacer una
 * reserva se cancela, y la cancelacion queda registrada con su motivo, su
 * fecha y su costo.
 *
 * El unico estado terminal de uso es `usada`: el pasajero viajo. Volver
 * atras desde ahi es un error de negocio, no un permiso.
 */
enum EstadoReserva: string
{
    /** Venta registrada, pendiente de pago o de confirmacion del proveedor. */
    case Pendiente = 'pendiente';

    /** Confirmada por el proveedor y por la agencia. El pasaje esta asegurado. */
    case Confirmada = 'confirmada';

    /** Boleto emitido al pasajero con el nombre tomado de su documento. */
    case Emitida = 'emitida';

    /** El pasajero viajo. Estado terminal. */
    case Usada = 'usada';

    /** Cancelada, con su costo aplicado segun la politica de la agencia. */
    case Cancelada = 'cancelada';

    /**
     * Transiciones permitidas.
     *
     * No es un grafo cualquiera: refleja lo que se puede hacer con un pasaje
     * en la realidad. Una reserva emitida no vuelve a confirmada (el boleto
     * ya salio; deshacerlo es un canje, que es otra operacion), y nada
     * vuelve desde `usada` ni desde `cancelada`.
     *
     * @return array<int, self>
     */
    public function transicionesPermitidas(): array
    {
        return match ($this) {
            self::Pendiente => [self::Confirmada, self::Cancelada],
            self::Confirmada => [self::Emitida, self::Cancelada],
            self::Emitida => [self::Usada],
            self::Usada => [],
            self::Cancelada => [],
        };
    }

    public function puedeTransicionarA(self $destino): bool
    {
        return in_array($destino, $this->transicionesPermitidas(), true);
    }

    public function esTerminal(): bool
    {
        return $this->transicionesPermitidas() === [];
    }

    /** Etiqueta para la interfaz, en espanol de Chile. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Confirmada => 'Confirmada',
            self::Emitida => 'Emitida',
            self::Usada => 'Viajada',
            self::Cancelada => 'Cancelada',
        };
    }
}