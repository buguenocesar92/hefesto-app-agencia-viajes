<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DocumentoViaje;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un documento de viaje en una respuesta de la API.
 *
 * ================================================================
 * EL NUMERO DEL DOCUMENTO NO SALE AQUI. NUNCA.
 * ================================================================
 * Este Resource es el que se usa en LISTADOS, y el listado es el lugar por
 * donde se escapan los datos: lo ve cualquiera que tenga acceso a la pantalla.
 *
 * Salen tres cosas del documento:
 *  - el TIPO (pasaporte, cedula),
 *  - los NOMBRES Y APELLIDOS (son los del documento, y es exactamente lo que
 *    la agencia necesita para imprimir el boleto),
 *  - el numero ENMASCARADO (`ABC12***`).
 *
 * Para ver el numero entero hay que llamar a
 * `GET /api/v1/pasajeros/{id}/documento`, que exige motivo y queda en la
 * bitacora. Ver docs/07.
 *
 * El enmascarado es de este Resource y NO del modelo a proposito: el modelo
 * oculta el campo, pero el Resource es el que decide que se muestra. Las dos
 * barreras estan, y la segunda es la que se lee al revisar la pantalla.
 */
class DocumentoViajeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        /** @var DocumentoViaje $documento */
        $documento = $this->resource;

        return [
            'id' => $documento->id,
            'tipo' => $documento->tipo->value,
            'tipo_etiqueta' => $documento->tipo->etiqueta(),

            // Enmascarado. El numero completo no sale de aqui.
            'numero_enmascarado' => $documento->numeroEnmascarado(
                (int) config('viajes.nombres.mascara_visibles')
            ),

            // El texto del documento, tal cual. De aqui sale el nombre del
            // boleto. No se transforma.
            'nombres' => $documento->nombres,
            'apellidos' => $documento->apellidos,

            'fecha_emision' => $documento->fecha_emision?->format('d-m-Y'),
            'fecha_vencimiento' => $documento->fecha_vencimiento?->format('d-m-Y'),
            'vencido' => $documento->estaVencido(),
            'pais_emision' => $documento->pais_emision,

            'es_principal' => $documento->es_principal,
            'estado_verificacion_nombre' => $documento->estado_verificacion_nombre->value,
            'estado_verificacion_etiqueta' => $documento->estado_verificacion_nombre->etiqueta(),
            'verificado_en' => $documento->verificado_en?->format('d-m-Y H:i'),

            'puede_emitir' => $documento->puedeEmitir(),
        ];
    }
}