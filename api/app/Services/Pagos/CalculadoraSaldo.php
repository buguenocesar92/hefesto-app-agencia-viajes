<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Enums\EstadoPago;
use App\Enums\EstadoReserva;
use App\Models\Pago;
use App\Models\Reserva;
use App\Models\Usuario;
use App\Services\Tenancy\AuditorDeAcceso;
use RuntimeException;

/**
 * Estado de cuenta del pasajero para una reserva.
 *
 * ============================================================
 * UN PASAJE PAGADO A MEDIAS Y NO DETECTADO ES LA PERDIDA DE PLATA CLASICA
 * ============================================================
 * El error no es que no se registre el pago: es que el sistema tenga un campo
 * "estado de pago" que alguien se olvide de actualizar. El sistema anuncia
 * "pagada" y en realidad le deben la mitad; o al reves, la agencia cree que
 * debe y el pasajero ya pago.
 *
 * POR QUE NO HAY COLUMNA `estado_pago`
 * -----------------------------------
 * EL ESTADO SE CALCULA, NO SE GUARDA. Se guarda el total de la reserva y la
 * suma de los pagos; el estado sale de comparar los dos. No hay un campo que
 * se pueda quedar viejo.
 *
 * DINERO EN CENTIMOS
 * -----------------
 * Todos los montos son enteros en centimos. Guardar pesos con decimales en
 * coma flotante produce diferencias de un peso entre el total, lo pagado y el
 * saldo, y esa diferencia es el tipo de descuadre que nadie encuentra
 * despues.
 */
class CalculadoraSaldo
{
    public function __construct(private readonly AuditorDeAcceso $auditor) {}

    /**
     * Estado de cuenta de una reserva.
     *
     * @return array{
     *     total_centimos: int,
     *     pagado_centimos: int,
     *     saldo_centimos: int,
     *     estado: EstadoPago,
     *     pagos: list<Pago>
     * }
     */
    public function estadoDeCuenta(Reserva $reserva): array
    {
        $pagos = Pago::query()
            ->where('reserva_id', $reserva->id)
            ->orderBy('creado_en')
            ->get();

        $pagado = (int) $pagos->where('anulado', false)->sum('monto_centimos');

        $anulada = $reserva->estado === EstadoReserva::Cancelada;

        return [
            'total_centimos' => $reserva->total_centimos,
            'pagado_centimos' => $pagado,
            'saldo_centimos' => $reserva->total_centimos - $pagado,
            'estado' => EstadoPago::desdeTotales($reserva->total_centimos, $pagado, $anulada),
            'pagos' => $pagos->all(),
        ];
    }

    /**
     * Registra un pago. Los pagos NO se editan ni se borran.
     *
     * Un pago equivocado se anula y se vuelve a registrar. Asi el estado de
     * cuenta siempre se puede reconstruir mirando la historia, que es lo que
     * hace falta cuando alguien reclama.
     *
     * @param  int  $montoCentimos  Monto en centimos. Tiene que ser positivo.
     * @throws RuntimeException si el monto no sirve o excede lo que se debe.
     */
    public function registrar(
        Reserva $reserva,
        int $montoCentimos,
        string $metodo,
        ?string $referencia = null,
        ?Usuario $usuario = null,
    ): Pago {
        if ($montoCentimos <= 0) {
            throw new RuntimeException('El monto del pago tiene que ser mayor que cero.');
        }

        if ($reserva->estado === EstadoReserva::Cancelada) {
            throw new RuntimeException(
                'No se pueden registrar pagos en una reserva cancelada. '
                . 'Si el pasajero devuelve dinero, se registra un pago a favor.'
            );
        }

        $saldo = $this->saldoPendiente($reserva);

        if ($montoCentimos > $saldo) {
            throw new RuntimeException(sprintf(
                'El pago es de $%s, pero la reserva solo tiene $%s pendientes. '
                . 'Si la diferencia es un saldo a favor del pasajero, no se registra como pago de esta reserva.',
                $this->formato($montoCentimos),
                $this->formato($saldo),
            ));
        }

        $pago = Pago::query()->create([
            'tenant_id' => $reserva->tenant_id,
            'reserva_id' => $reserva->id,
            'monto_centimos' => $montoCentimos,
            'metodo' => $metodo,
            'referencia' => $referencia,
            'anulado' => false,
            'registrado_por' => $usuario?->id,
            'creado_en' => now(),
        ]);

        $this->auditor->registrar(
            'pago.registrado',
            'pago',
            $pago->id,
            'Pago de ' . $this->formato($montoCentimos) . ' a la reserva ' . $reserva->folio,
        );

        return $pago;
    }

    /**
     * Anula un pago, sin borrarlo.
     */
    public function anular(Pago $pago, string $motivo, ?Usuario $usuario = null): Pago
    {
        if ($pago->anulado) {
            throw new RuntimeException('El pago ya estaba anulado.');
        }

        if (trim($motivo) === '') {
            throw new RuntimeException('Anular un pago exige un motivo.');
        }

        $pago->anulado = true;
        $pago->anulado_motivo = $motivo;
        $pago->anulado_en = now();
        $pago->save();

        $this->auditor->registrar(
            'pago.registrado',
            'pago',
            $pago->id,
            'Pago anulado: ' . $motivo,
        );

        return $pago->refresh();
    }

    /** Lo que falta pagar. Si esta en cero, la reserva esta pagada. */
    public function saldoPendiente(Reserva $reserva): int
    {
        return max(0, $reserva->total_centimos - $reserva->pagadoCentimos());
    }

    private function formato(int $centimos): string
    {
        return number_format(intdiv($centimos, 100), 0, ',', '.');
    }
}