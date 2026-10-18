<?php

declare(strict_types=1);

namespace App\Http\Requests\Reservas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de estado de una reserva.
 *
 * `estado` se valida contra el enum. Que el estado DESTINO sea valido en
 * general no significa que sea valido DESDE el estado actual: eso lo comprueba
 * MaquinaDeEstadosReserva, que conoce la maquina de estados y devuelve un
 * error que dice que transiciones si son posibles.
 */
class CambiarEstadoRequest extends FormRequest
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
            'estado' => ['required', Rule::enum(\App\Enums\EstadoReserva::class)],
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'estado.required' => 'Indique el estado nuevo de la reserva.',
        ];
    }
}