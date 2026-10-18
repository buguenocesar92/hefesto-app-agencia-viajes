<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Reserva;
use App\Models\Usuario;

/**
 * Permisos sobre reservas.
 *
 * DOBLE FILTRO
 * ------------
 * La RLS ya garantiza que una agencia no ve los datos de otra: eso no lo puede
 * hacer esta Policy, esta en la base. Lo que la Policy hace es el SEGUNDO
 * filtro, DENTRO de la agencia: que un agente no pueda cambiar el estado de una
 * reserva que no es suya si la agencia lo restringe.
 *
 * Cuando una Policy rechaza, la respuesta es 404, no 403: un 403 confirmaria
 * que el recurso existe. Como la RLS ya impide que exista a ojos del otro, 404
 * es ademas la respuesta correcta. Ver docs/03.
 */
class ReservaPolicy
{
    /** Cualquier agente autenticado de la agencia puede ver las reservas. */
    public function ver(Usuario $usuario, Reserva $reserva): bool
    {
        return $this->mismaAgencia($usuario, $reserva);
    }

    /** Crear reservas: cualquier agente activo. */
    public function crear(Usuario $usuario): bool
    {
        return $usuario->activo;
    }

    /**
     * Mover una reserva de estado.
     *
     * Un agente puede mover una reserva hasta `confirmada`. Emitirla exige
     * ademas que los nombres esten verificados (eso lo comprueba
     * MaquinaDeEstadosReserva), y cancelarla aplica la politica de cancelacion
     * de la agencia.
     */
    public function actualizar(Usuario $usuario, Reserva $reserva): bool
    {
        return $this->mismaAgencia($usuario, $reserva) && $usuario->activo;
    }

    /**
     * Registrar pagos.
     *
     * Es una habilidad separada de `actualizar` a proposito: cobrar no es lo
     * mismo que editar el estado de la venta. Un agente que puede confirmar una
     * reserva no necesariamente deberia poder registrar un pago.
     */
    public function pagar(Usuario $usuario, Reserva $reserva): bool
    {
        return $this->mismaAgencia($usuario, $reserva) && $usuario->activo;
    }

    /**
     * Verificar el nombre del boleto.
     *
     * Es la habilidad MAS DELICADA del sistema: es la unica que convierte un
     * nombre "pendiente" en un nombre "verificado", que es lo que despues
     * permite emitir el boleto.
     *
     * Por defecto la tienen todos los agentes activos de la agencia, porque en
     * una agencia chica el que vende es el que verifica. Si la agencia quiere
     * separarlo, se cambia aqui, no con una bandera en la base.
     */
    public function verificarNombre(Usuario $usuario, Reserva $reserva): bool
    {
        return $this->mismaAgencia($usuario, $reserva) && $usuario->activo;
    }

    private function mismaAgencia(Usuario $usuario, Reserva $reserva): bool
    {
        return $usuario->tenant_id !== null && $usuario->tenant_id === $reserva->tenant_id;
    }
}