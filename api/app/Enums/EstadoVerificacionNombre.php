<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de la revision del nombre que se imprime en el boleto.
 *
 * ESTE ES EL CORAZON DEL PRODUCTO
 * -------------------------------
 * El nombre del boleto sale del documento con el que viaja el pasajero, y
 * la correspondencia entre lo que escribio y lo que dice el documento la
 * revisa un AGENTE, no el sistema. El sistema no "arregla" nombres: no sabe
 * cual de las dos grafias es la buena, y adivinar es exactamente lo que
 *provoca que un pasajero no pueda abordar.
 */
enum EstadoVerificacionNombre: string
{
    /** El boleto se armo desde el documento, pero nadie lo ha revisado. */
    case Pendiente = 'pendiente';

    /** Un agente confirmo que el nombre del boleto coincide con el documento. */
    case Verificado = 'verificado';

    /** Un agente determino que NO coincide. La emision queda bloqueada. */
    case Rechazado = 'rechazado';

    /**
     * Solo se puede emitir con el nombre verificado.
     *
     * Emitir con el nombre pendiente es el error que el producto evita.
     */
    public function permiteEmitir(): bool
    {
        return $this === self::Verificado;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente de revisión',
            self::Verificado => 'Verificado',
            self::Rechazado => 'Rechazado',
        };
    }
}