<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Precio de un producto en una fecha.
 *
 * DINERO EN CENTIMOS, SIEMPRE
 * --------------------------
 * `precio_centimos` es un entero. Un ticket de vuelo no lleva decimales, y
 * guardar el precio como decimal en coma flotante produce diferencias de un
 * peso entre el total, lo pagado y el saldo. Esa diferencia es el descuadre
 * que nadie encuentra despues.
 */
class Tarifa extends Model
{
    use HasUuids;

    protected $table = 'tarifas';

    protected $fillable = [
        'tenant_id',
        'producto_id',
        'vuelo_id',
        'nombre_clase',
        'precio_centimos',
        'moneda',
        'cupos_disponibles',
        'vigente_desde',
        'vigente_hasta',
    ];

    protected function casts(): array
    {
        return [
            'precio_centimos' => 'integer',
            'cupos_disponibles' => 'integer',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * La tarifa puede estar atada a una fecha de vuelo concreta o servir para
     * cualquier fecha del producto (tarifa base).
     *
     * @return BelongsTo<Vuelo, $this>
     */
    public function vuelo(): BelongsTo
    {
        return $this->belongsTo(Vuelo::class);
    }

    public function vigenteEn(string $fecha): bool
    {
        $fecha = substr($fecha, 0, 10);

        if ($this->vigente_desde !== null && $fecha < $this->vigente_desde->format('Y-m-d')) {
            return false;
        }

        if ($this->vigente_hasta !== null && $fecha > $this->vigente_hasta->format('Y-m-d')) {
            return false;
        }

        return true;
    }
}