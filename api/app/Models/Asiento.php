<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoAsiento;
use App\Enums\TipoProducto;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un asiento de un vuelo, en una fecha concreta.
 *
 * AQUI ESTA EL MODULO DE CONCURRENCIA
 * ===================================
 * Un asiento no es "un campo que se marca": es un recurso que se TOMA. Si dos
 * compras simultaneas pueden tomar el mismo asiento, una de las dos personas
 * llega al aeropuerto con un boleto que no existe.
 *
 * LA GARANTIA NO ESTA EN ESTA CLASE
 * --------------------------------
 * Esta clase no impide nada por si sola. La garantia esta en la base:
 *
 *   ALTER TABLE asientos ADD CONSTRAINT asientos_no_duplicados
 *     EXCLUDE USING gist (vuelo_id WITH =, numero WITH =)
 *     WHERE (estado IN ('bloqueado', 'asignado'));
 *
 * Un indice de exclusion es una regla que PostgreSQL aplica dentro de la
 * transaccion. Dos INSERT simultaneos del mismo (vuelo, numero) en estado
 * ocupado: uno entra y el otro recibe un error de violacion. No hay ventana
 * entre "comprobar que esta libre" y "marcarlo como tomado", porque no hay
 * dos pasos: hay uno.
 *
 * Por que NO se resuelve solo con un candado de Redis: esta tabla lo explica
 * en detalle. En resumen: Redis se cae y quedan asientos vendidos dos veces,
 * mientras que el indice de exclusion esta en la misma transaccion que la
 * venta. Ver docs/01, seccion "Bloqueo de asientos".
 *
 * @property string $numero  Por ejemplo "14A". Case-sensitive: la aerolinea distingue mayusculas.
 * @property EstadoAsiento $estado
 */
class Asiento extends Model
{
    use HasUuids;

    protected $table = 'asientos';

    protected $fillable = [
        'tenant_id',
        'vuelo_id',
        'numero',
        'clase',
        'estado',
        'reserva_id',
        'reserva_pasajero_id',
        'bloqueado_por_usuario_id',
        'bloqueado_hasta',
        'bloqueado_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoAsiento::class,
            'bloqueado_hasta' => 'datetime',
            'bloqueado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Vuelo, $this> */
    public function vuelo(): BelongsTo
    {
        return $this->belongsTo(Vuelo::class);
    }

    /** @return BelongsTo<Reserva, $this> */
    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    /** @return BelongsTo<Usuario, $this> */
    public function bloqueadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'bloqueado_por_usuario_id');
    }

    /** El asiento ocupa lugar: compite con otros en el indice de exclusion. */
    public function ocupa(): bool
    {
        return $this->estado->ocupa();
    }

    /** El bloqueo vencio y hay que liberarlo antes de reutilizarlo. */
    public function bloqueoVencido(): bool
    {
        return $this->estado === EstadoAsiento::Bloqueado
            && $this->bloqueado_hasta !== null
            && $this->bloqueado_hasta->isPast();
    }

    /** Un bloqueo vigente mantiene el asiento tomado. */
    public function bloqueoVigente(): bool
    {
        return $this->estado === EstadoAsiento::Bloqueado
            && $this->bloqueado_hasta !== null
            && $this->bloqueado_hasta->isFuture();
    }

    /**
     * Etiqueta para la interfaz: "14A - Economy".
     */
    public function etiqueta(): string
    {
        return $this->numero . ($this->clase !== null ? ' - ' . $this->clase : '');
    }

    /**
     * El asiento es de un producto que usa asientos (vuelo). Un hotel no tiene
     * asientos: tiene habitaciones, y su bloqueo es otro problema.
     */
    public function esDeVuelo(): bool
    {
        return TipoProducto::Vuelo->usaAsiento();
    }
}