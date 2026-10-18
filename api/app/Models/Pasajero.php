<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una persona que viaja con la agencia.
 *
 * LA PERSONA Y EL DOCUMENTO SON COSAS DISTINTAS
 * ---------------------------------------------
 * Aqui NO hay una columna de documento. El documento con el que se viaja vive
 * en `documentos_viaje`, como una fila propia con su historial.
 *
 * El motivo es concreto: un pasajero puede viajar en junio con el pasaporte A,
 * renovar, y viajar en diciembre con el pasaporte B. Si el documento fuera
 * una columna del pasajero, la segunda vez se sobreescribiria la primera y el
 * boleto de junio quedaria apuntando al documento equivocado: exactamente el
 * problema que esta aplicacion existe para evitar.
 *
 * Lo que si se guarda aqui es lo que la persona declara de si misma: como le
 * dicen. Eso puede estar abreviado ("Rodrigo") y no es el nombre del boleto.
 *
 * @property string|null $nombre_declarado  Como lo escribio el pasajero. No es el nombre del boleto.
 */
class Pasajero extends Model
{
    use HasUuids;

    protected $table = 'pasajeros';

    protected $fillable = [
        'tenant_id',
        'nombre_declarado',
        'email',
        'telefono',
        'fecha_nacimiento',
        'nacionalidad',
        'activo',
    ];

    /** El email y el telefono son datos personales: no salen por defecto. */
    protected $hidden = [
        'email',
        'telefono',
        'fecha_nacimiento',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'activo' => 'boolean',
        ];
    }

    /**
     * Todos los documentos que ha presentado esta persona en la agencia.
     *
     * @return HasMany<DocumentoViaje, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoViaje::class, 'pasajero_id');
    }

    /** @return HasMany<ReservaPasajero, $this> */
    public function reservas(): HasMany
    {
        return $this->hasMany(ReservaPasajero::class, 'pasajero_id');
    }

    /**
     * El documento vigente, que es el mas reciente que no esta vencido.
     *
     * ADVERTENCIA: este metodo es una ayuda para PROPONER un documento, no
     * para emitir. Que sea el mas reciente no significa que sea el correcto
     * para el viaje: para emitir hay que elegir el documento explicito de ese
     * viaje y que un agente haya verificado el nombre. Ver
     * App\Services\Viajes\ConstruccionBoleto.
     */
    public function documentoVigente(): ?DocumentoViaje
    {
        $hoy = now()->toDateString();

        return $this->documentos()
            ->where(function ($consulta) use ($hoy): void {
                $consulta->whereNull('vencido_en')
                    ->orWhere('vencido_en', '>=', $hoy);
            })
            ->orderByDesc('es_principal')
            ->orderByDesc('creado_en')
            ->first();
    }
}