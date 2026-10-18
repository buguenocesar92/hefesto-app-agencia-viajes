<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reglas de cancelacion de un producto, escritas por la agencia.
 *
 * ESTA TABLA NO TIENE VALORES POR DEFECTO
 * --------------------------------------
 * Un plazo de cancelacion no es un dato tecnico: es un acuerdo comercial
 * entre la agencia, su proveedor y el pasajero. Si esta tabla esta vacia para
 * un producto, el sistema NO asume "se cancela gratis": RECHAZA la
 * cancelacion y avisa que falta configurar la politica.
 *
 * Los escalones se guardan en minutos de anticipacion. El sistema elige el
 * escalon que corresponde: el de mayor anticipacion que sea menor o igual a
 * las horas que faltan para salir.
 *
 * @property int $anticipacion_minima_minutos  Desde cuanto antes aplica este escalon.
 * @property int $porcentaje_costo  Porcentaje del total a cobrar. De 0 a 100.
 * @property int $costo_fijo_centimos  Monto fijo adicional, en centimos.
 * @property string $destino  Que pasa con el dinero: "reembolso" o "credito".
 */
class PoliticaCancelacion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'politicas_cancelacion';

    protected $fillable = [
        'tenant_id',
        'producto_id',
        'nombre',
        'anticipacion_minima_minutos',
        'porcentaje_costo',
        'costo_fijo_centimos',
        'destino',
        'activo',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'anticipacion_minima_minutos' => 'integer',
            'porcentaje_costo' => 'integer',
            'costo_fijo_centimos' => 'integer',
            'activo' => 'boolean',
            'creado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * Si este escalon aplica a una cancelacion hecha con esta antelacion.
     *
     * El parametro son MINUTOS que faltan para la salida. Si faltan 3.600
     * minutos (un dia) y este escalon exige 4.320 minutos (tres dias), NO
     * aplica: es el escalon de dentro de tres dias, no el de un dia antes.
     */
    public function aplicaCon(int $minutosRestantes): bool
    {
        return $minutosRestantes <= $this->anticipacion_minima_minutos;
    }

    /** Una etiqueta legible, para mostrar el escalon sin traducir codigos. */
    public function etiqueta(): string
    {
        $horas = intdiv($this->anticipacion_minima_minutos, 60);
        $dias = intdiv($horas, 24);

        if ($dias >= 1) {
            $plazo = $dias === 1 ? '1 dia' : $dias . ' dias';
        } elseif ($horas >= 1) {
            $plazo = $horas === 1 ? '1 hora' : $horas . ' horas';
        } else {
            $plazo = $this->anticipacion_minima_minutos . ' minutos';
        }

        return $this->nombre . ': ' . $plazo . ' de anticipacion, '
            . $this->porcentaje_costo . '% de costo ('
            . $this->destino . ')';
    }
}