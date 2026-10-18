<?php

declare(strict_types=1);

namespace App\Http\Requests\Reservas;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Verificacion del nombre de un pasajero en una reserva.
 *
 * ESTE REQUEST ES LA FRONTERA DEL PRODUCTO
 * ----------------------------------------
 * El nombre del boleto sale del documento, y el sistema lo deja PENDIENTE.
 * Passar a `verificado` o `rechazado` lo decide un AGENTE, y por eso el estado
 * se acepta aqui, en un endpoint explicito, y queda con su autor y su hora.
 *
 * `observacion` no es obligatoria en el formato, pero el servicio la exige
 * cuando el estado es `rechazado`: rechazar sin decir por que deja al pasajero
 * y al siguiente agente sin informacion.
 */
class VerificarNombreRequest extends FormRequest
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
            'estado' => ['required', 'in:verificado,rechazado'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'estado.in' => 'Un nombre solo puede marcarse como verificado o como rechazado. '
                . 'El estado "pendiente" lo pone el sistema, no el agente.',
            'observacion.max' => 'La observacion es demasiado larga.',
        ];
    }

    /**
     * Rechazar sin explicacion deja el problema sin resolver para el pasajero.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'observacion' => 'observacion',
        ];
    }
}