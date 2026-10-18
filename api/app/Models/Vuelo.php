<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una FECHA CONCRETA de salida de un vuelo.
 *
 * "EL MISMO VUELO EXISTE EN VARIAS FECHAS"
 * ----------------------------------------
 * El vuelo SCL-SPA de la aerolinea X es un `Producto`, uno solo. De el salen
 * muchas filas en esta tabla: el vuelo del lunes, el del martes, el del
 * siguiente mes. Cada una tiene su propio horario y, sobre todo, SU PROPIA
 * TABLA DE ASIENTOS.
 *
 * Por eso la reserva no apunta al producto: apunta a un `vuelo_id`. Si
 * apuntara al producto, dos personas que viajan el mismo tramo en fechas
 * distintas compartirian los mismos asientos.
 *
 * @property string $id
 * @property string $producto_id
 * @property string $codigo_vuelo
 * @property \Illuminate\Support\Carbon $salida_en
 * @property \Illuminate\Support\Carbon $llegada_en
 */
class Vuelo extends Model
{
    use HasUuids;

    protected $table = 'vuelos';

    protected $fillable = [
        'tenant_id',
        'producto_id',
        'codigo_vuelo',
        'origen',
        'destino',
        'salida_en',
        'llegada_en',
        'aeronave',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'salida_en' => 'datetime',
            'llegada_en' => 'datetime',
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /** @return HasMany<Asiento, $this> */
    public function asientos(): HasMany
    {
        return $this->hasMany(Asiento::class, 'vuelo_id');
    }

    /** @return HasMany<Tarifa, $this> */
    public function tarifas(): HasMany
    {
        return $this->hasMany(Tarifa::class, 'vuelo_id');
    }

    /** Si el vuelo ya salio, no se le puede asignar ni bloquear un asiento. */
    public function yaDespegado(): bool
    {
        return $this->salida_en->isPast();
    }

    public function tramo(): string
    {
        return $this->origen . ' - ' . $this->destino;
    }
}