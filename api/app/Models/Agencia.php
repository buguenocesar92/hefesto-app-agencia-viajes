<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La agencia de viajes: el tenant.
 *
 * Esta tabla NO lleva Row Level Security, a proposito. Se consulta durante el
 * login, cuando todavia no hay sesion y por lo tanto no hay
 * `app.tenant_id` que poner en la conexion. Se compensa con dos medidas:
 *
 *  1. la vista `vw_agencias_publicas`, que expone solo slug, nombre y activo;
 *  2. el rol de la base de datos no es superusuario ni tiene BYPASSRLS.
 *
 * Ver docs/03-MULTITENANCY-Y-ROLES.md, seccion "La tabla que no lleva RLS".
 *
 * @property string $id
 * @property string $slug
 * @property string $nombre
 * @property string|null $razon_social
 * @property string|null $rut
 * @property bool $activo
 */
class Agencia extends Model
{
    use HasUuids;

    protected $table = 'agencias';

    protected $fillable = [
        'slug',
        'nombre',
        'razon_social',
        'rut',
        'dominio_correo',
        'activo',
    ];

    /** La razon social y el RUT son datos de la agencia, no secretos de sesion. */
    protected $hidden = [
        'dominio_correo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<Usuario, $this> */
    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'tenant_id', 'id');
    }

    /** @return HasMany<Producto, $this> */
    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'tenant_id', 'id');
    }

    /** @return HasMany<Reserva, $this> */
    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class, 'tenant_id', 'id');
    }

    /**
     * La configuracion de la agencia, o null si todavia no la creo nadie.
     *
     * La configuracion puede faltar: en ese caso, los servicios usan el
     * comportamiento prudente de config/viajes.php (por ejemplo, la
     * cancelacion sin politica se rechaza). No inventan un valor.
     */
    public function configuracion(): ?Configuracion
    {
        return $this->hasOne(Configuracion::class, 'tenant_id', 'id')->first();
    }
}