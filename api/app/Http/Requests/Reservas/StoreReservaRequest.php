<?php

declare(strict_types=1);

namespace App\Http\Requests\Reservas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creacion de una reserva.
 *
 * QUIEN SE VALIDA Y QUE NO
 * -------------------------
 * Se valida que el vuelo pertenezca a un producto, que la tarifa sea del
 * producto, y que los pasajeros y documentos existan. NO se valida que el
 * documento corresponda a un pasajero de la misma agencia: eso lo resuelve la
 * RLS, y una consulta sin contexto de agencia falla sola.
 */
class StoreReservaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'producto_id' => ['required', 'uuid'],
            'vuelo_id' => ['nullable', 'uuid'],
            'tarifa_id' => ['nullable', 'uuid'],
            'origen' => ['required', Rule::in(['mostrador', 'web', 'telefono'])],

            'pasajeros' => ['required', 'array', 'min:1', 'max:20'],
            'pasajeros.*.pasajero_id' => ['required', 'uuid'],

            // El documento es POR PASAJERO y POR VIAJE: el mismo pasajero puede
            // viajar con un documento distinto al de su viaje anterior.
            'pasajeros.*.documento_viaje_id' => ['required', 'uuid'],
            'pasajeros.*.es_contacto' => ['nullable', 'boolean'],

            // Sobrescribe el total calculado desde la tarifa. Solo para
            // correcciones de la agencia.
            'total_centimos' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pasajeros.required' => 'Una reserva necesita al menos un pasajero.',
            'pasajeros.*.documento_viaje_id.required' => 'Cada pasajero necesita el documento con el que viaja en ESTE viaje.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'producto_id' => 'producto',
            'vuelo_id' => 'fecha de vuelo',
            'total_centimos' => 'total',
            'pasajeros' => 'pasajeros',
        ];
    }
}