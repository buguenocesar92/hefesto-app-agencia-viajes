<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Parametros de la agencia que no son reglas de negocio pero si cambian como
 * se comporta el sistema.
 *
 * `mostrar_documento_en_listados` esta en false y no deberia cambiar. Existe
 * porque alguien, en algun momento, va a querer ponerlo en true "para que sea
 * mas rapido", y queda a la vista que la decision se tomo de forma consciente
 * y no por descuido. Ver docs/07.
 */
class Configuracion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'configuraciones';

    protected $fillable = [
        'tenant_id',
        'mostrar_documento_en_listados',
        'exigir_verificacion_nombre',
        'exigir_motivo_lectura_documento',
        'dias_aviso_vuelo',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'mostrar_documento_en_listados' => 'boolean',
            'exigir_verificacion_nombre' => 'boolean',
            'exigir_motivo_lectura_documento' => 'boolean',
            'dias_aviso_vuelo' => 'integer',
            'creado_en' => 'datetime',
        ];
    }

    /**
     * El comportamiento prudente cuando no hay configuracion.
     *
     * Todos en false / restrictivo. Una agencia recien creada no muestra
     * documentos en listados: primero se configura, despues se abre.
     */
    public static function prudente(string $tenantId): self
    {
        return new self([
            'tenant_id' => $tenantId,
            'mostrar_documento_en_listados' => false,
            'exigir_verificacion_nombre' => true,
            'exigir_motivo_lectura_documento' => true,
            'dias_aviso_vuelo' => 7,
        ]);
    }
}