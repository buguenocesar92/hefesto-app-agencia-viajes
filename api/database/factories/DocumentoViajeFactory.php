<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoVerificacionNombre;
use App\Enums\TipoDocumento;
use App\Models\DocumentoViaje;
use App\Models\Pasajero;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Documentos de viaje para las pruebas y la semilla.
 *
 * Los datos que genera son de FAKER: ningun numero de documento es real.
 *
 * `nombres` y `apellidos` se generan con acentos a proposito. La comparacion de
 * nombres es justamente el caso dificil: "Pérez" contra "PEREZ" tiene que dar
 * diferencia, y una prueba con nombres sin tilde no comprueba nada.
 */
class DocumentoViajeFactory extends Factory
{
    protected $model = DocumentoViaje::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Pasajero::factory(),
            'tipo' => TipoDocumento::Pasaporte,
            'numero' => Str::upper($this->faker->bothify('P######')),
            'nombres' => $this->faker->firstName() . ' ' . $this->faker->firstName(),
            'apellidos' => $this->faker->lastName() . ' ' . $this->faker->lastName(),
            'fecha_emision' => now()->subYears(5)->toDateString(),
            'fecha_vencimiento' => now()->addYears(5)->toDateString(),
            'vencido_en' => now()->addYears(10)->toDateString(),
            'pais_emision' => 'CL',
            'es_principal' => true,
            'estado_verificacion_nombre' => EstadoVerificacionNombre::Pendiente,
            'verificado_por' => null,
            'verificado_en' => null,
            'observacion_verificacion' => null,
        ];
    }

    /** Documento con el nombre YA verificado por un agente. */
    public function verificado(): static
    {
        return $this->state(fn (): array => [
            'estado_verificacion_nombre' => EstadoVerificacionNombre::Verificado,
        ]);
    }

    /** Documento rechazado: el nombre del boleto no coincide con el documento. */
    public function rechazado(): static
    {
        return $this->state(fn (): array => [
            'estado_verificacion_nombre' => EstadoVerificacionNombre::Rechazado,
        ]);
    }

    public function dePasaporte(): static
    {
        return $this->state(fn (): array => ['tipo' => TipoDocumento::Pasaporte]);
    }

    public function deCedula(): static
    {
        return $this->state(fn (): array => ['tipo' => TipoDocumento::Cedula]);
    }
}