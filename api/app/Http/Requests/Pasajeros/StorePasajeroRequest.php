<?php

declare(strict_types=1);

namespace App\Http\Requests\Pasajeros;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta o edicion de un pasajero.
 *
 * `nombre_declarado` es como el pasajero se escribe a si mismo. NO es el
 * nombre del boleto: el del boleto sale del documento, y se arma en
 * ConstruccionBoleto. Por eso aqui se acepta cualquier grafia, incluso
 * abreviada: el sistema no la corrige ni la juzga.
 */
class StorePasajeroRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorizacion fina la hace la Policy. Aca solo se exige sesion.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre_declarado' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['nullable', 'email:rfc', 'max:200'],
            'telefono' => ['nullable', 'string', 'max:32'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],

            // Lista cerrada. Se valida contra el enum, no contra una lista
            // escrita a mano que se desincroniza del codigo.
            'nacionalidad' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre_declarado.required' => 'Indique el nombre del pasajero tal como lo declara.',
            'nombre_declarado.min' => 'El nombre del pasajero es demasiado corto.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre_declarado' => 'nombre declarado',
            'fecha_nacimiento' => 'fecha de nacimiento',
        ];
    }

    /**
     * Se documenta el enum aunque no se use aqui, para que quede a mano cuando
     * se agregue el filtro.
     *
     * @return array<int, string>
     */
    public static function nacionalidades(): array
    {
        return ['CL'];
    }

    /**
     * @return array<int, string>
     */
    public function reglasTipoDocumento(): array
    {
        return array_map(
            static fn (\App\Enums\TipoDocumento $tipo): string => $tipo->value,
            \App\Enums\TipoDocumento::cases(),
        );
    }

    /**
     * @param  string  $campo
     * @return array<int, mixed>
     */
    public static function reglaTipoDocumento(string $campo = 'tipo'): array
    {
        return [Rule::enum(\App\Enums\TipoDocumento::class)];
    }
}