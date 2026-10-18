<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\EstadoPago;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas del calculo del estado de pago.
 *
 * SON PRUEBAS PURAS: no tocan la base de datos, asi que se pueden correr sin
 * PostgreSQL. Comprueban la regla que evita el problema clasico de la plata:
 * el estado se CALCULA comparando totales, no se guarda.
 *
 * NO SE EJECUTARON: en el entorno donde se escribio el codigo no habia PHP.
 * Ver docs/10.
 */
class CalculoDeEstadoPagoTest extends TestCase
{
    public function test_sin_pagos_esta_pendiente(): void
    {
        $this->assertSame(EstadoPago::Pendiente, EstadoPago::desdeTotales(1800000, 0));
    }

    public function test_con_un_pago_parcial_queda_parcial(): void
    {
        $this->assertSame(EstadoPago::Parcial, EstadoPago::desdeTotales(1800000, 700000));
    }

    public function test_pagada_exactamente_igual_al_total_queda_pagada(): void
    {
        $this->assertSame(EstadoPago::Pagada, EstadoPago::desdeTotales(1800000, 1800000));
    }

    public function test_un_pago_de_mas_queda_pagada_y_no_una_tercera_cosa(): void
    {
        // El saldo se acota en cero, asi que un sobrepago es un caso raro pero
        // tiene que tener un comportamiento definido, no quedar en un limbo.
        $this->assertSame(EstadoPago::Pagada, EstadoPago::desdeTotales(1800000, 2000000));
    }

    public function test_una_reserva_cancelada_queda_anulada(): void
    {
        $this->assertSame(
            EstadoPago::Anulada,
            EstadoPago::desdeTotales(1800000, 1800000, anulada: true)
        );
    }
}