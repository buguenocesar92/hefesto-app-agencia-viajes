<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoVerificacionNombre;
use App\Enums\TipoDocumento;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El documento con el que viaja un pasajero, en un viaje concreto.
 *
 * ESTA ES LA TABLA DE LA QUE SALE EL NOMBRE DEL BOLETO
 * ---------------------------------------------------
 * `nombres` y `apellidos` son el texto del documento, tal como esta. El
 * sistema NO los transforma, NO los abrevia, NO les cambia el orden y NO les
 * invierte. Si el documento dice "Rodrigo Andrés Pérez", el boleto sale con
 * "Rodrigo Andrés Pérez".
 *
 * Que la correspondencia entre lo que el pasajero escribio y lo que dice el
 * documento sea la correcta NO LO DECIDE EL CODIGO: lo decide un agente, y
 * queda registrado en `estado_verificacion_nombre`, `verificado_por` y
 * `verificado_en`.
 *
 * Ver App\Services\Viajes\ConstruccionBoleto y docs/01, seccion "El nombre
 * del boleto".
 *
 * @property TipoDocumento $tipo
 * @property string $numero  Dato personal bajo la Ley 19.628: nunca en listados.
 * @property string $nombres
 * @property string $apellidos
 */
class DocumentoViaje extends Model
{
    use HasUuids;

    protected $table = 'documentos_viaje';

    protected $fillable = [
        'tenant_id',
        'pasajero_id',
        'tipo',
        'numero',
        'nombres',
        'apellidos',
        'fecha_emision',
        'fecha_vencimiento',
        'vencido_en',
        'pais_emision',
        'es_principal',
        'estado_verificacion_nombre',
        'verificado_por',
        'verificado_en',
        'observacion_verificacion',
    ];

    /**
     * El numero del documento NO sale nunca en un listado.
     *
     * Se oculta a nivel de modelo, no solo en el Resource: asi, aunque alguien
     * olvide escribir el Resource correcto, el numero no sale. La excepcion es
     * el endpoint de lectura completa, que es explicito y queda auditado.
     */
    protected $hidden = [
        'numero',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDocumento::class,
            'estado_verificacion_nombre' => EstadoVerificacionNombre::class,
            'fecha_emision' => 'date',
            'fecha_vencimiento' => 'date',
            'vencido_en' => 'date',
            'es_principal' => 'boolean',
            'verificado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Pasajero, $this> */
    public function pasajero(): BelongsTo
    {
        return $this->belongsTo(Pasajero::class);
    }

    /** @return BelongsTo<Usuario, $this> */
    public function verificadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'verificado_por');
    }

    /** @return HasMany<ReservaPasajero, $this> */
    public function viajes(): HasMany
    {
        return $this->hasMany(ReservaPasajero::class, 'documento_viaje_id');
    }

    /**
     * El nombre completo tal como sale en el boleto, SIN transformar.
     *
     * El separador lo pone la politica de nombres de la agencia
     * (App\Services\Viajes\PoliticaNombres). Si no hay politica configurada,
     * se usa un espacio, que es lo mas cercano al documento y no inventa nada.
     */
    public function nombreCompleto(string $separador = ' '): string
    {
        return trim($this->nombres . $separador . $this->apellidos);
    }

    /** Version enmascarada del numero, la unica que sale en listados. */
    public function numeroEnmascarado(int $visibles = 3): string
    {
        $numero = (string) $this->getAttribute('numero');

        if ($numero === '') {
            return '';
        }

        if (mb_strlen($numero) <= $visibles) {
            return str_repeat('*', mb_strlen($numero));
        }

        return mb_substr($numero, 0, $visibles) . str_repeat('*', min(5, mb_strlen($numero) - $visibles));
    }

    public function estaVencido(?string $fecha = null): bool
    {
        $referencia = $fecha !== null
            ? \Illuminate\Support\Carbon::parse($fecha)
            : now();

        return $this->vencido_en !== null && $this->vencido_en->lessThan($referencia);
    }

    /**
     * ¿El nombre del boleto puede emitirse?
     *
     * Solo si un agente ya lo verifico. Ver
     * EstadoVerificacionNombre::permiteEmitir().
     */
    public function puedeEmitir(): bool
    {
        return $this->estado_verificacion_nombre->permiteEmitir();
    }
}