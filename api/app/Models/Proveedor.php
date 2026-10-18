<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Proveedor: la aerolinea, el hotel o el mayorista con el que opera la
 * agencia.
 *
 * Se aisla por agencia como todo lo demas: dos agencias pueden tener la misma
 * aerolinea como proveedor, con sus propias condiciones comerciales. Por eso
 * el proveedor lleva `tenant_id` y no se comparte entre agencias.
 */
class Proveedor extends Model
{
    use HasUuids;

    protected $table = 'proveedores';

    protected $fillable = [
        'tenant_id',
        'tipo',
        'codigo',
        'nombre',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<Producto, $this> */
    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'proveedor_id');
    }
}