<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Asiento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un asiento para el mapa de la pantalla.
 *
 * No trae datos de pasajero: solo el estado. El mapa de asientos lo ve
 * cualquiera de la agencia, y no tiene por que ver QUIEN esta sentado en cada
 * asiento. Para eso esta el detalle de la reserva.
 */
class AsientoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        /** @var Asiento $asiento */
        $asiento = $this->resource;

        return [
            'id' => $asiento->id,
            'numero' => $asiento->numero,
            'clase' => $asiento->clase,
            'etiqueta' => $asiento->etiqueta(),
            'estado' => $asiento->estado->value,
            'estado_etiqueta' => $asiento->estado->etiqueta(),

            // Es lo que la interfaz necesita para pintar el boton: si se
            // puede hacer clic o no.
            'disponible' => ! $asiento->ocupa() && $asiento->estado->value !== 'no_disponible',
            'ocupado' => $asiento->ocupa(),
        ];
    }
}