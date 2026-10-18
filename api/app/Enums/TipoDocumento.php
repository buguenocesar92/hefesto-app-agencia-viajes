<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Documento con el que viaja un pasajero.
 *
 * UN PASAJERO PUEDE TENER VARIOS VIAJES CON DISTINTOS DOCUMENTOS
 * ------------------------------------------------------------
 * La misma persona viaja hoy con pasaporte y en marzo con un documento
 * distinto, o renueva su pasaporte y el nuevo no es el que se cargó la vez
 * pasada. Por eso el documento NO es una columna del pasajero: es una tabla
 * aparte (`documentos_viaje`), con historial, y la reserva apunta al
 * documento concreto con el que se viaja ESE viaje.
 *
 * Asumir un documento por persona es un error clasico que produce el
 * problema que este producto existe para evitar: el boleto queda con el
 * nombre del documento viejo y el pasajero viaja con el nuevo.
 */
enum TipoDocumento: string
{
    case Pasaporte = 'pasaporte';
    case Cedula = 'cedula';
    case DocumentoDeViaje = 'documento_de_viaje';
    case Dni = 'dni';

    /**
     * Si este tipo de documento define el nombre de la persona.
     *
     * Todos lo definen, pero con caracteristicas distintas: el pasaporte
     * suele traer los nombres tal como estan en el registro civil, mientras
     * que un documento interno puede venir abreviado. Por eso el sistema no
     * asume que "el mismo nombre escrito distinto esta bien": lo marca para
     * revision de un agente. Ver App\Services\Viajes\ConstruccionBoleto.
     */
    public function esRegistroCivil(): bool
    {
        return $this === self::Pasaporte || $this === self::Cedula || $this === self::Dni;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pasaporte => 'Pasaporte',
            self::Cedula => 'Cédula de identidad',
            self::DocumentoDeViaje => 'Documento de viaje',
            self::Dni => 'DNI',
        };
    }
}