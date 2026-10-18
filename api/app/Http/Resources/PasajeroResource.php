<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Pasajero;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un pasajero en una respuesta de la API.
 *
 * NO SE MUESTRA EL DOCUMENTO COMPLETO EN UN LISTADO
 * -------------------------------------------------
 * Este Resource es de LISTADO. Trae el pasajero, sus documentos ENMASCARADOS
 * (ver DocumentoViajeResource) y nada de email, telefono ni fecha de
 * nacimiento, que estan en `$hidden` del modelo.
 *
 * `nombre_declarado` es como el pasajero se escribe a si mismo. El nombre del
 * BOLETO no es este: es el del documento, y se ve en `documentos`.
 */
class PasajeroResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        /** @var Pasajero $pasajero */
        $pasajero = $this->resource;

        return [
            'id' => $pasajero->id,
            'nombre_declarado' => $pasajero->nombre_declarado,
            'nacionalidad' => $pasajero->nacionalidad,
            'activo' => $pasajero->activo,

            // Cantos viajes tiene con la agencia. El mismo pasajero puede
            // tener varios: es el caso normal, no una excepcion.
            'viajes' => $pasajero->reservas()->count(),

            // Documentos ENMASCARADOS. Para ver el numero entero hay que
            // pedirlo en el endpoint de lectura completa, con motivo.
            'documentos' => DocumentoViajeResource::collection($pasajero->documentos),

            'creado_en' => $pasajero->created_at?->format('d-m-Y H:i'),
        ];
    }
}