<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\EstadoReserva;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de la maquina de estados de la reserva.
 *
 * SON PRUEBAS PURAS: no tocan la base de datos, asi que se pueden correr sin
 * PostgreSQL. Comprueban el grafo de transiciones, que es la regla que impide
 * que una reserva se borre en vez de pasar por estados.
 *
 * NO SE EJECUTARON: en el entorno donde se escribio el codigo no habia PHP.
 * Ver docs/10.
 */
class MaquinaDeEstadosReservaTest extends TestCase
{
    public function test_una_reserva_pendiente_puede_confirmarse_o_cancelarse(): void
    {
        $this->assertTrue(EstadoReserva::Pendiente->puedeTransicionarA(EstadoReserva::Confirmada));
        $this->assertTrue(EstadoReserva::Pendiente->puedeTransicionarA(EstadoReserva::Cancelada));
    }

    public function test_una_reserva_confirmada_puede_emitirse_o_cancelarse(): void
    {
        $this->assertTrue(EstadoReserva::Confirmada->puedeTransicionarA(EstadoReserva::Emitida));
        $this->assertTrue(EstadoReserva::Confirmada->puedeTransicionarA(EstadoReserva::Cancelada));
    }

    public function test_una_reserva_emitida_no_vuelve_a_confirmada(): void
    {
        // El boleto ya salio. Deshacerlo es un canje, que es otra operacion
        // con otro registro, no un cambio de estado.
        $this->assertFalse(EstadoReserva::Emitida->puedeTransicionarA(EstadoReserva::Confirmada));
        $this->assertFalse(EstadoReserva::Emitida->puedeTransicionarA(EstadoReserva::Pendiente));
    }

    public function test_viajada_y_cancelada_son_terminales(): void
    {
        // "UNA RESERVA NO SE CANCELA: PASA POR ESTADOS". Borrarla destruye el
        // historial, asi que no hay transiciones de salida.
        $this->assertTrue(EstadoReserva::Usada->esTerminal());
        $this->assertTrue(EstadoReserva::Cancelada->esTerminal());

        $this->assertFalse(EstadoReserva::Usada->puedeTransicionarA(EstadoReserva::Cancelada));
        $this->assertFalse(EstadoReserva::Cancelada->puedeTransicionarA(EstadoReserva::Pendiente));
    }

    public function test_todas_las_transiciones_son_de_un_estado_valido(): void
    {
        foreach (EstadoReserva::cases() as $origen) {
            foreach ($origen->transicionesPermitidas() as $destino) {
                $this->assertInstanceOf(EstadoReserva::class, $destino);
                $this->assertNotSame($origen, $destino, 'Un estado no puede transicionar a si mismo.');
            }
        }
    }
}