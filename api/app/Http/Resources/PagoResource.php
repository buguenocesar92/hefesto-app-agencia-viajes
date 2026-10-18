<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Pago;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un pago de la lista.
 *
 * `anulado` va siempre. Un pago anulado que no se ve marcado como tal es un
 * pago que se cuenta dos veces o que nunca se cuenta.
 */
class PagoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        /** @var Pago $pago */
        $pago = $this->resource;

        return [
            'id' => $pago->id,
            'reserva_id' => $pago->reserva_id,
            'monto' => $pago->monto_centimos,
            'monto_formateado' => '$' . $pago->monto(),
            'metodo' => $pago->metodo,
            'referencia' => $pago->referencia,
            'anulado' => $pago->anulado,
            'anulado_motivo' => $pago->anulado_motivo,
            'registrado_por' => $pago->registradoPor?->nombre,
            'creado_en' => $pago->creado_en?->format('d-m-Y H:i'),
        ];
    }
}