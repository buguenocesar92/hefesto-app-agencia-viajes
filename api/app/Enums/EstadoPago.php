<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de cuenta de una reserva: debe, pagado o saldado.
 *
 * UN PASAJE PAGADO A MEDIAS Y NO DETECTADO ES LA PERDIDA DE PLATA CLASICA.
 * ----------------------------------------------------------------------
 * El estado no se guarda: se CALCULA. Se guarda la suma de los pagos y el
 * total de la reserva, y el saldo es la diferencia. Guardar un estado que
 * alguien puede olvidar de actualizar es como se pierde la plata: el sistema
 * dice "pagada" y en realidad le deben la mitad.
 *
 * Por eso no existe la columna `estado_pago` en `reservas`.
 */
enum EstadoPago: string
{
    case Pendiente = 'pendiente';
    case Parcial = 'parcial';
    case Pagada = 'pagada';
    case Anulada = 'anulada';

    /**
     * Calcula el estado a partir del total y lo pagado.
     *
     * El saldo se guarda en centimos (entero) y no en pesos con decimales:
     * las sumas de dinero en coma flotante producen diferencia de un peso
     * entre el total, los pagos y el saldo, y esa diferencia es exactamente
     * el tipo de descuadre que nadie encuentra despues.
     */
    public static function desdeTotales(int $totalCentimos, int $pagadoCentimos, bool $anulada = false): self
    {
        if ($anulada) {
            return self::Anulada;
        }

        if ($pagadoCentimos <= 0) {
            return self::Pendiente;
        }

        if ($pagadoCentimos >= $totalCentimos) {
            return self::Pagada;
        }

        return self::Parcial;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Sin pagar',
            self::Parcial => 'Pago parcial',
            self::Pagada => 'Pagada',
            self::Anulada => 'Anulada',
        };
    }
}