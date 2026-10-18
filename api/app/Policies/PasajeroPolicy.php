<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DocumentoViaje;
use App\Models\Pasajero;
use App\Models\Usuario;

/**
 * Permisos sobre pasajeros y sus documentos.
 *
 * PERMISOS MINIMOS SOBRE EL DATO MAS SENSIBLE
 * -----------------------------------------
 * Ver un pasajero (con el documento ENMASCARADO) no es lo mismo que ver su
 * documento completo. Son habilidades distintas, y la segunda es la que se
 * audita.
 */
class PasajeroPolicy
{
    public function ver(Usuario $usuario, Pasajero $pasajero): bool
    {
        return $usuario->tenant_id !== null && $usuario->tenant_id === $pasajero->tenant_id;
    }

    public function crear(Usuario $usuario): bool
    {
        return $usuario->activo;
    }

    public function actualizar(Usuario $usuario, Pasajero $pasajero): bool
    {
        return $usuario->activo && $usuario->tenant_id === $pasajero->tenant_id;
    }

    /**
     * LEER EL DOCUMENTO COMPLETO.
     *
     * Es el permiso mas restringido del sistema. Se separa de `ver` porque ver
     * el listado de pasajeros es una necesidad diaria y leer el numero entero
     * de un documento no lo es.
     *
     * Las dos capas:
     *  - esta Policy decide si el agente puede;
     *  - el controlador exige MOTIVO y escribe en la bitacora.
     *
     * Ver docs/07.
     */
    public function leerDocumentoCompleto(Usuario $usuario, Pasajero $pasajero): bool
    {
        return $this->actualizar($usuario, $pasajero);
    }

    /**
     * Habilidad de listado de una clase de modelo.
     *
     * Se usa para autorizar los listados, donde no hay una instancia concreta
     * todavia.
     *
     * @param  class-string  $modelo
     */
    public function verCualquiera(Usuario $usuario, string $modelo): bool
    {
        return $usuario->activo;
    }
}