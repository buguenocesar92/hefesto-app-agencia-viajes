<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoReserva;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cambio de estado en el historial de una reserva.
 *
 * Esta tabla es la respuesta a "que paso con esta reserva". Sin ella, un
 * estado que se cambio y despues se cambio de nuevo deja de contarse, y la
 * agencia pierde la capacidad de explicar su propia operacion.
 *
 * Se escribe DENTRO de la misma transaccion que el cambio de estado: si el
 * historial no se puede escribir, el cambio tampoco. Un cambio de estado sin
 * rastro es peor que un cambio que no ocurrio.
 */
class ReservaEvento extends Model
{
    public $timestamps = false;

    protected $table = 'reserva_eventos';

    protected $fillable = [
        'tenant_id',
        'reserva_id',
        'estado_anterior',
        'estado_nuevo',
        'motivo',
        'costo_centimos',
        'usuario_id',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'estado_anterior' => EstadoReserva::class,
            'estado_nuevo' => EstadoReserva::class,
            'costo_centimos' => 'integer',
            'creado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Reserva, $this> */
    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    /** @return BelongsTo<Usuario, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function descripcion(): string
    {
        $desde = $this->estado_anterior->etiqueta();
        $hasta = $this->estado_nuevo->etiqueta();

        return $desde . ' -> ' . $hasta . ($this->motivo !== null ? ' (' . $this->motivo . ')' : '');
    }
}