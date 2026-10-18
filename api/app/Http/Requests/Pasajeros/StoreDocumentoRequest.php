<?php

declare(strict_types=1);

namespace App\Http\Requests\Pasajeros;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta o edicion de un documento de viaje.
 *
 * `nombres` y `apellidos` son EL TEXTO DEL DOCUMENTO, y no se corrigen ni se
 * completan. El agente escribe lo que dice el documento. Si el sistema
 * "arreglara" un nombre aca, el boleto saldria con un nombre que nadie
 * verifico, que es el problema que esta aplicacion existe para evitar.
 *
 * `numero` no se devuelve nunca en un listado. Aca es un campo de entrada, que
 * es exactamente donde tiene que estar. Ver DocumentoViajeResource.
 */
class StoreDocumentoRequest extends FormRequest
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
            'pasajero_id' => ['required', 'uuid'],

            // Se valida contra el enum, no contra una lista escrita a mano.
            'tipo' => ['required', Rule::enum(\App\Enums\TipoDocumento::class)],

            'numero' => ['required', 'string', 'min:3', 'max:60'],

            'nombres' => ['required', 'string', 'min:2', 'max:200'],
            'apellidos' => ['required', 'string', 'min:2', 'max:200'],

            'fecha_emision' => ['nullable', 'date'],
            'fecha_vencimiento' => ['nullable', 'date', 'after_or_equal:fecha_emision'],
            'vencido_en' => ['nullable', 'date'],
            'pais_emision' => ['nullable', 'string', 'max:80'],
            'es_principal' => ['nullable', 'boolean'],

            // El estado de verificacion NO se acepta desde la API.
            //
            // Si el agente pudiera mandar "verificado" al crear el documento,
            // la revision humana seria opcional y el producto pierde su
            // sentido. Verificar es una operacion aparte, con su registro. Ver
            // MaquinaDeEstadosReserva::verificarNombre().
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombres.required' => 'Escriba los nombres TAL COMO APARECEN en el documento. El boleto sale de aqui.',
            'apellidos.required' => 'Escriba los apellidos TAL COMO APARECEN en el documento. El boleto sale de aqui.',
            'numero.required' => 'Indique el numero del documento.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombres' => 'nombres del documento',
            'apellidos' => 'apellidos del documento',
            'fecha_vencimiento' => 'fecha de vencimiento',
            'fecha_emision' => 'fecha de emisión',
        ];
    }
}