<?php

declare(strict_types=1);

namespace App\Http\Requests\Pagos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registro de un pago, que puede ser parcial.
 *
 * `monto_centimos` es un entero. Se pide en centimos y no en pesos para que
 * no haya decimales de mas: un pago de "45000.50" no existe en una agencia de
 * viajes, y permitirlo es la primera forma de que el saldo no cuadre.
 */
class StorePagoRequest extends FormRequest
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
            'monto_centimos' => ['required', 'integer', 'min:1'],
            'metodo' => ['required', Rule::in(['transferencia', 'efectivo', 'tarjeta', 'credito'])],
            'referencia' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monto_centimos.required' => 'Indique el monto del pago, en centimos.',
            'monto_centimos.min' => 'El pago tiene que ser mayor que cero.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'monto_centimos' => 'monto',
        ];
    }
}