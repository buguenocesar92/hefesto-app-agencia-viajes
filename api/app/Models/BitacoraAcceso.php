<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de acceso a datos personales.
 *
 * Un pasajero es una persona natural y su documento de identidad es dato
 * personal bajo la Ley 19.628. Esta tabla es lo que permite responder, meses
 * despues, a la pregunta "quien vio mis datos y por que".
 *
 * Ver docs/07-SEGURIDAD-Y-LEY-19628.md.
 *
 * @property string $accion
 * @property string|null $motivo  Por que se accedio. Obligatorio al leer el documento entero.
 */
class BitacoraAcceso extends Model
{
    public $timestamps = false;

    protected $table = 'bitacora_accesos';

    protected $fillable = [
        'tenant_id',
        'usuario_id',
        'accion',
        'recurso',
        'recurso_id',
        'motivo',
        'ip',
        'agente',
        'detalle',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'detalle' => 'array',
            'creado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Usuario, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    /** Descripcion legible para la pantalla de auditoria. */
    public function descripcion(): string
    {
        $quien = $this->usuario?->nombreParaAuditoria() ?? 'sistema';
        $motivo = $this->motivo !== null ? ' - motivo: ' . $this->motivo : '';

        return $this->createdAtFormat()
            . ' | ' . $quien . ' | ' . $this->accion
            . ' | ' . $this->recurso . ($this->recurso_id !== null ? '#' . $this->recurso_id : '')
            . $motivo;
    }

    private function createdAtFormat(): string
    {
        return $this->creado_en?->format('d-m-Y H:i') ?? '';
    }
}