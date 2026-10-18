<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un agente de la agencia (o el administrador de ella).
 *
 * ROLES
 * -----
 *  - `agente`: opera reservas, pasajeros, asientos y pagos. No cambia la
 *    configuracion de la agencia ni ve a otros agentes.
 *  - `administrador`: todo lo anterior mas proveedores, productos, tarifas y
 *    politicas (nombres y cancelacion).
 *
 * El rol NO viene de Authentik como autoridad final: viene de la tabla de la
 * agencia. Authentik dice quien entro; esta tabla dice que puede hacer dentro
 * de la agencia. Ver docs/03, seccion "Roles".
 */
class Usuario extends Model
{
    use HasUuids;

    protected $table = 'usuarios';

    protected $fillable = [
        'tenant_id',
        'subjekt_authentik',
        'nombre',
        'email',
        'rol',
        'activo',
        'ultimo_acceso',
    ];

    protected $hidden = [
        'subjekt_authentik',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'ultimo_acceso' => 'datetime',
        ];
    }

    /** @return BelongsTo<Agencia, $this> */
    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class, 'tenant_id', 'id');
    }

    public function esAdministrador(): bool
    {
        return $this->rol === 'administrador';
    }

    /**
     * Identificador legible, para la bitacora y los logs.
     *
     * El `subjekt_authentik` no se muestra: es un identificador tecnico que no
     * le sirve de nada a quien lea el registro.
     */
    public function nombreParaAuditoria(): string
    {
        return $this->nombre . ' <' . $this->email . '>';
    }

    /** Iniciales para la interfaz. */
    public function iniciales(): string
    {
        return Str::of($this->nombre)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(static fn (string $parte): string => Str::upper(Str::substr($parte, 0, 1)))
            ->implode('');
    }
}