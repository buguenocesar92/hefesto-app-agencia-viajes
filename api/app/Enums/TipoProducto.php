<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de producto que vende la agencia.
 *
 * El tipo decide dos cosas, y solo dos:
 *  1. Si la reserva necesita ASIENTO (un vuelo tiene asientos, un hotel tiene
 *     habitacion y no un numero de asiento).
 *  2. Que reglas de cancelacion se le aplican. Las reglas son SIEMPRE las que
 *     configuro la agencia para ese producto; este enum no propone ninguna.
 */
enum TipoProducto: string
{
    case Vuelo = 'vuelo';
    case Hotel = 'hotel';
    case Paquete = 'paquete';

    /** Un producto de este tipo ocupa un asiento en un vuelo. */
    public function usaAsiento(): bool
    {
        return $this === self::Vuelo;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Vuelo => 'Vuelo',
            self::Hotel => 'Hotel',
            self::Paquete => 'Paquete',
        };
    }
}