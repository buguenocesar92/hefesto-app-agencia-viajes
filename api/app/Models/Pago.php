<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoReserva;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pago (o una devolucion) registrado contra una reserva.
 *
 * LOS PAGOS SE REGISTRAN, NO SE EDITAN
 * -------------------------------------
 * Un pago no se modifica ni se borra: se anula y se vuelve a registrar. Asi el
 * estado de cuenta siempre se puede reconstruir mirando la historia, que es
 * justo lo que se necesita cuando un pasajero reclama.
 *
 * `monto_centimos` es un entero. Un pago parcial de 45.000 pesos son 4.500.000
 * de centimos. Nunca se guarda como decimal.
 *
 * @property int $monto_centimos
 * @property bool $anulado
 */
class Pago extends Model
{
    public $timestamps = false;

    protected $table = 'pagos';

    protected $fillable = [
        'tenant_id',
        'reserva_id',
        'monto_centimos',
        'metodo',
        'referencia',
        'anulado',
        'anulado_motivo',
        'anulado_en',
        'registrado_por',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'monto_centimos' => 'integer',
            'anulado' => 'boolean',
            'anulado_en' => 'datetime',
            'creado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Reserva, $this> */
    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    /** @return BelongsTo<Usuario, $this> */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por');
    }

    public function monto(): string
    {
        // Se formatea con intdiv, no con division: (int) (45000 / 100) en
        // coma flotante puede dar 449 con algunos valores. intdiv es entera.
        return number_format(intdiv($this->monto_centimos, 100), 0, ',', '.');
    }
}