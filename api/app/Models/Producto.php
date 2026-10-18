<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoProducto;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Producto que vende la agencia: un vuelo, un hotel o un paquete.
 *
 * El producto es el CATALOGO, no una venta. Lo que se vende es una `Tarifa` de
 * ese producto en una fecha concreta.
 *
 * Un mismo vuelo comercial existe en varias fechas: el producto "SCL-SPA,
 * aerolinea X" es uno solo, y de el salen muchas filas en `vuelos`, una por
 * fecha de salida. La reserva apunta a la fecha, no al producto.
 */
class Producto extends Model
{
    use HasUuids;

    protected $table = 'productos';

    protected $fillable = [
        'tenant_id',
        'proveedor_id',
        'tipo',
        'codigo',
        'nombre',
        'descripcion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoProducto::class,
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Proveedor, $this> */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    /** @return HasMany<Tarifa, $this> */
    public function tarifas(): HasMany
    {
        return $this->hasMany(Tarifa::class, 'producto_id');
    }

    /** @return HasMany<Vuelo, $this> */
    public function vuelos(): HasMany
    {
        return $this->hasMany(Vuelo::class, 'producto_id');
    }

    /** @return HasMany<PoliticaCancelacion, $this> */
    public function politicasCancelacion(): HasMany
    {
        return $this->hasMany(PoliticaCancelacion::class, 'producto_id');
    }

    public function usaAsientos(): bool
    {
        return $this->tipo->usaAsiento();
    }
}