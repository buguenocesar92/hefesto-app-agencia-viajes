<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de un asiento en una fecha de vuelo concreta.
 *
 * Aqui esta el modulo de concurrencia del producto, asi que los estados
 * importan por una razon precisa: SOLO los estados "ocupantes" compiten entre
 * si. La restriccion de exclusion de PostgreSQL se aplica sobre
 * `config('viajes.asientos.estados_ocupantes')`, o sea estos dos. Un asiento
 * `disponible` no bloquea nada; un asiento `liberado` vuelve a estar
 * disponible.
 */
enum EstadoAsiento: string
{
    /** Se puede asignar. */
    case Disponible = 'disponible';

    /** Tomado por una compra en curso, con vencimiento automatico. */
    case Bloqueado = 'bloqueado';

    /** Asignado a un pasajero de una reserva confirmada o emitida. */
    case Asignado = 'asignado';

    /** Se libero: vuelve a estar disponible y queda el rastro del uso. */
    case Liberado = 'liberado';

    /** No existe en la cabina (asiento dado de baja por la aerolinea). */
    case NoDisponible = 'no_disponible';

    /** Este asiento compite con otro: el mismo numero en otra clase. */
    public function ocupa(): bool
    {
        return $this === self::Bloqueado || $this === self::Asignado;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Bloqueado => 'Bloqueado',
            self::Asignado => 'Asignado',
            self::Liberado => 'Liberado',
            self::NoDisponible => 'No disponible',
        };
    }
}