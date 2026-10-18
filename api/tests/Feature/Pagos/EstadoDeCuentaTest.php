<?php

declare(strict_types=1);

namespace Tests\Feature\Pagos;

use App\Enums\EstadoPago;
use App\Enums\EstadoReserva;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\Reserva;
use App\Services\Pagos\CalculadoraSaldo;
use App\Services\Tenancy\AuditorDeAcceso;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * PRUEBAS CRITICAS: el pasaje pagado a medias se ve, y el saldo cuadra.
 *
 * "UN PASAJE PAGADO A MEDIAS Y NO DETECTADO ES LA PERDIDA DE PLATA CLASICA"
 * ----------------------------------------------------------------------
 * Lo que se comprueba aca:
 *
 *  1. El saldo se CALCULA: no hay columna que se pueda quedar vieja.
 *  2. Un pago parcial queda visible como tal, con su saldo.
 *  3. Anular un pago cambia el saldo, y el anulado no se cuenta.
 *  4. No se puede pagar mas de lo que se debe.
 *  5. No se puede pagar una reserva cancelada.
 *  6. La pantalla de pagos incompletos los lista.
 *
 * NO SE EJECUTARON. Ver docs/10.
 */
class EstadoDeCuentaTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_saldo_se_calcula_y_no_se_guarda(): void
    {
        $reserva = $this->crearReserva(total: 4500000);

        $this->assertSame(0, $reserva->pagadoCentimos());
        $this->assertSame(4500000, $reserva->saldoCentimos());
        $this->assertSame(EstadoPago::Pendiente, $reserva->estadoPago());

        // La tabla no tiene columna de estado de pago. Si la tuviera, el
        // estado guardado y el calculado podrian no coincidir.
        $columnas = \Illuminate\Support\Facades\DB::select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_name = 'reservas' AND column_name = 'estado_pago'"
        );

        $this->assertCount(0, $columnas, 'La tabla reservas no debe tener columna estado_pago.');
    }

    public function test_un_pago_parcial_queda_visible_con_su_saldo(): void
    {
        $escenario = $this->crearReserva(total: 1800000);

        $this->saldo()->registrar(
            $escenario,
            700000,
            'transferencia',
            'TRANSF-123',
            $escenario['agente'],
        );

        $reserva = $escenario['reserva']->refresh();

        $this->assertSame(700000, $reserva->pagadoCentimos());
        $this->assertSame(1100000, $reserva->saldoCentimos());
        $this->assertSame(EstadoPago::Parcial, $reserva->estadoPago());

        // Y el sistema lo marca como el caso que hay que mirar.
        $this->assertTrue($reserva->pagoIncompleto());
    }

    public function test_una_reserva_pagada_completa_no_aparece_como_incompleta(): void
    {
        $escenario = $this->crearReserva(total: 1800000);

        $this->saldo()->registrar($escenario, 1800000, 'efectivo', null, $escenario['agente']);

        $reserva = $escenario['reserva']->refresh();

        $this->assertSame(EstadoPago::Pagada, $reserva->estadoPago());
        $this->assertFalse($reserva->pagoIncompleto());
    }

    public function test_anular_un_pago_devuelve_el_saldo_y_el_anulado_no_se_cuenta(): void
    {
        $escenario = $this->crearReserva(total: 1800000);

        $pago = $this->saldo()->registrar($escenario, 700000, 'transferencia', 'TRANSF-123', $escenario['agente']);
        $this->assertSame(1100000, $escenario['reserva']->refresh()->saldoCentimos());

        $this->saldo()->anular($pago, 'Se reverses el pago en el banco.', $escenario['agente']);

        $reserva = $escenario['reserva']->refresh();

        // El pago anulado sigue en la historia, pero no suma.
        $this->assertCount(1, $reserva->pagos()->get());
        $this->assertSame(0, $reserva->pagadoCentimos());
        $this->assertSame(1800000, $reserva->saldoCentimos());
        $this->assertSame(EstadoPago::Pendiente, $reserva->estadoPago());
    }

    public function test_no_se_puede_pagar_mas_de_lo_que_se_debe(): void
    {
        $escenario = $this->crearReserva(total: 1800000);

        $this->saldo()->registrar($escenario, 1500000, 'transferencia', null, $escenario['agente']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/pendientes/');

        $this->saldo()->registrar($escenario, 500000, 'efectivo', null, $escenario['agente']);
    }

    public function test_no_se_puede_registrar_un_pago_de_cero(): void
    {
        $escenario = $this->crearReserva(total: 1800000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/mayor que cero/');

        $this->saldo()->registrar($escenario, 0, 'efectivo', null, $escenario['agente']);
    }

    public function test_no_se_puede_pagar_una_reserva_cancelada(): void
    {
        $escenario = $this->crearReserva(total: 1800000);
        $escenario['reserva']->estado = EstadoReserva::Cancelada;
        $escenario['reserva']->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cancelada/');

        $this->saldo()->registrar($escenario, 100000, 'efectivo', null, $escenario['agente']);
    }

    public function test_anular_un_pago_ya_anulado_falla(): void
    {
        $escenario = $this->crearReserva(total: 1800000);

        $pago = $this->saldo()->registrar($escenario, 700000, 'efectivo', null, $escenario['agente']);
        $this->saldo()->anular($pago, 'Error de tipeo en el monto.', $escenario['agente']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ya estaba anulado/');

        $this->saldo()->anular($pago, 'Otra vez.', $escenario['agente']);
    }

    public function test_la_lista_de_pagos_incompletos_solo_trae_los_que_faltan(): void
    {
        $parcial = $this->crearReserva(total: 1800000);
        $this->saldo()->registrar($parcial, 700000, 'transferencia', null, $parcial['agente']);

        $pagada = $this->crearReserva(total: 900000);
        $this->saldo()->registrar($pagada, 900000, 'efectivo', null, $pagada['agente']);

        $this->comoAgente($parcial['agente']);

        $respuesta = $this->getJson('/api/v1/pagos/incompletos')->assertOk();

        $folios = array_column($respuesta->json('data'), 'folio');

        $this->assertContains($parcial['reserva']->folio, $folios);
        $this->assertNotContains($pagada['reserva']->folio, $folios);

        // Y la respuesta trae el saldo de cada una, que es lo que hace util la
        // pantalla.
        $fila = collect($respuesta->json('data'))->firstWhere('folio', $parcial['reserva']->folio);

        $this->assertSame(1100000, $fila['saldo']);
        $this->assertTrue($fila['pago_incompleto']);
    }

    public function test_el_estado_de_cuenta_devuelve_el_saldo_sin_que_se_pida(): void
    {
        $escenario = $this->crearReserva(total: 1800000);
        $this->saldo()->registrar($escenario, 700000, 'transferencia', null, $escenario['agente']);

        $this->comoAgente($escenario['agente']);

        $respuesta = $this->getJson("/api/v1/reservas/{$escenario['reserva']->id}/estado-de-cuenta")->assertOk();

        $respuesta->assertJson([
            'total' => 1800000,
            'pagado' => 700000,
            'saldo' => 1100000,
            'estado' => 'parcial',
        ]);
    }

    private function saldo(): CalculadoraSaldo
    {
        return new CalculadoraSaldo(new AuditorDeAcceso());
    }

    /**
     * @return array{reserva: Reserva, agente: Usuario}
     */
    private function crearReserva(int $total): array
    {
        $agencia = $this->crearAgencia('primera.cl');
        $this->fijarAgencia($agencia['agencia']->id);

        $producto = Producto::factory()->vuelo()->create([
            'tenant_id' => $agencia['agencia']->id,
        ]);

        $reserva = Reserva::factory()->deTotal($total)->create([
            'tenant_id' => $agencia['agencia']->id,
            'producto_id' => $producto->id,
            'creada_por' => $agencia['agente']->id,
        ]);

        return ['reserva' => $reserva, 'agente' => $agencia['agente']];
    }
}