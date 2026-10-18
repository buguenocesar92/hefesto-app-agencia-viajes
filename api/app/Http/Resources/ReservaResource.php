<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Reserva;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una reserva en una respuesta de la API.
 *
 * INCLUYE EL SALDO, SIEMPRE
 * -------------------------
 * El saldo va en la respuesta aunque no se pida, porque una pantalla de
 * reservas sin saldo es una pantalla donde se pierde plata sin que nadie lo
 * note. Se calcula al momento (ver Reserva::saldoCentimos), no se lee de un
 * campo guardado.
 */
class ReservaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        /** @var Reserva $reserva */
        $reserva = $this->resource;

        $estadoPago = $reserva->estadoPago();

        return [
            'id' => $reserva->id,
            'folio' => $reserva->folio,

            'producto' => [
                'id' => $reserva->producto_id,
                'nombre' => $reserva->producto?->nombre,
                'tipo' => $reserva->producto?->tipo->value,
            ],

            // La FECHA CONCRETA, no el producto. Dos viajes del mismo trayecto
            // son dos reservas distintas.
            'vuelo' => $reserva->vuelo === null ? null : [
                'id' => $reserva->vuelo->id,
                'codigo_vuelo' => $reserva->vuelo->codigo_vuelo,
                'tramo' => $reserva->vuelo->tramo(),
                'salida_en' => $reserva->vuelo->salida_en->format('d-m-Y H:i'),
            ],

            'estado' => $reserva->estado->value,
            'estado_etiqueta' => $reserva->estado->etiqueta(),
            'origen' => $reserva->origen,

            'total' => $reserva->total_centimos,
            'pagado' => $reserva->pagadoCentimos(),
            'saldo' => $reserva->saldoCentimos(),
            'estado_pago' => $estadoPago->value,
            'estado_pago_etiqueta' => $estadoPago->etiqueta(),

            // La bandera del problema clasico: pagado a medias y nadie lo ha
            // detectado. La interfaz la usa para resaltar la fila.
            'pago_incompleto' => $reserva->pagoIncompleto(),

            // El nombre del boleto y en que estado esta su verificacion.
            // Los nombres SI salen: son el dato con el que se viaja, no el
            // documento. El documento va enmascarado.
            'pasajeros' => $reserva->pasajeros->map(static fn ($pasajero): array => [
                'id' => $pasajero->id,
                'pasajero_id' => $pasajero->pasajero_id,
                'nombre_boleto' => $pasajero->nombre_boleto,
                'estado_verificacion_nombre' => $pasajero->estado_verificacion_nombre->value,
                'estado_verificacion_etiqueta' => $pasajero->estado_verificacion_nombre->etiqueta(),
                'puede_emitir' => $pasajero->puedeEmitir(),
                'es_contacto' => $pasajero->es_contacto,
                'asiento' => $pasajero->asiento?->numero,
            ])->values()->all(),

            'emitida_en' => $reserva->emitida_en?->format('d-m-Y H:i'),
            'cancelada_en' => $reserva->cancelada_en?->format('d-m-Y H:i'),
            'motivo_cancelacion' => $reserva->motivo_cancelacion,
            'costo_cancelacion' => $reserva->costo_cancelacion_centimos,

            'creado_en' => $reserva->creado_en?->format('d-m-Y H:i'),
        ];
    }
}