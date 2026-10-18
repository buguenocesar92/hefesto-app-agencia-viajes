<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pasajero dentro de una reserva concreta.
 *
 * Esta tabla es la bisagra entre dos cosas que se confunden a menudo:
 * la PERSONA (que puede viajar muchas veces) y el VIAJE (que lleva un
 * documento concreto).
 *
 * `documento_viaje_id` es el documento con el que viaja en ESTA reserva. No es
 * "el documento del pasajero": es el de este viaje. Por eso un mismo pasajero
 * puede tener reservas apuntando a documentos distintos.
 *
 * `nombre_boleto` es una COPIA del nombre del documento en el momento de
 * armar el boleto.
 *
 * POR QUE ES UNA COPIA Y NO UNA REFERENCIA
 * ----------------------------------------
 * El boleto ya salio. Si manana el pasajero actualiza su documento, el boleto
 * que ya se emitio tiene que seguir diciendo lo que decia ayer. Guardar solo
 * la referencia y leerla al vuelo haria que un boleto emitido ayer apareciera
 * hoy con el nombre nuevo, que es un nombre que nunca se compro.
 *
 * La copia se llena SIEMPRE con el texto del documento, nunca con
 * `nombre_declarado`. Ver App\Services\Viajes\ConstruccionBoleto.
 */
class ReservaPasajero extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'reserva_pasajeros';

    protected $fillable = [
        'tenant_id',
        'reserva_id',
        'pasajero_id',
        'documento_viaje_id',
        'nombre_boleto',
        'estado_verificacion_nombre',
        'verificado_por',
        'verificado_en',
        'observacion_verificacion',
        'es_contacto',
    ];

    protected function casts(): array
    {
        return [
            'estado_verificacion_nombre' => \App\Enums\EstadoVerificacionNombre::class,
            'verificado_en' => 'datetime',
            'es_contacto' => 'boolean',
        ];
    }

    /** @return BelongsTo<Reserva, $this> */
    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    /** @return BelongsTo<Pasajero, $this> */
    public function pasajero(): BelongsTo
    {
        return $this->belongsTo(Pasajero::class);
    }

    /** @return BelongsTo<DocumentoViaje, $this> */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoViaje::class, 'documento_viaje_id');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function verificadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'verificado_por');
    }

    /** @return BelongsTo<Asiento, $this> */
    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class, 'reserva_pasajero_id');
    }

    public function puedeEmitir(): bool
    {
        return $this->estado_verificacion_nombre->permiteEmitir();
    }
}