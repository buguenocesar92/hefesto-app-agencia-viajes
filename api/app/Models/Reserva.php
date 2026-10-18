<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPago;
use App\Enums\EstadoReserva;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La venta: una reserva de la agencia.
 *
 * UNA RESERVA NO SE BORRA
 * -----------------------
 * La clase no tiene `delete()`, y el modelo no permite borrado fisico. Para
 * deshacer una venta se cambia el estado a `Cancelada`, que deja el rastro:
 * existio una venta, quien la hizo, cuanto se cobro y con que costo se
 * cancelo. Borrarla dejaria el historial de la agencia incompleto.
 *
 * UN MISMO PASAJERO, VARIOS VIAJES
 * -------------------------------
 * La reserva tiene N passengers (`reserva_pasajeros`). El mismo pasajero puede
 * estar en diez reservas distintas con la misma agencia: es el caso normal,
 * no una excepcion.
 *
 * EL SALDO SE CALCULA, NO SE GUARDA
 * ---------------------------------
 * No hay columna `estado_pago`. El estado sale de comparar el total con la
 * suma de los pagos. Guardarlo seria el modo clasico de perder plata: el
 * sistema anuncia "pagada" y en realidad le deben la mitad.
 *
 * @property int $total_centimos
 * @property EstadoReserva $estado
 */
class Reserva extends Model
{
    use HasUuids;

    /**
     * PostgreSQL actualiza `updated_at` solo. Aqui el cambio de estado es un
     * hecho de negocio que se registra en `reserva_eventos`, asi que no se
     * toca la fecha de modificacion: la fecha de modificacion es la ultima
     * vez que se toco un dato cualquiera.
     */
    public $timestamps = false;

    protected $table = 'reservas';

    protected $fillable = [
        'tenant_id',
        'folio',
        'producto_id',
        'vuelo_id',
        'tarifa_id',
        'proveedor_id',
        'estado',
        'origen',
        'total_centimos',
        'emitida_en',
        'usada_en',
        'cancelada_en',
        'motivo_cancelacion',
        'costo_cancelacion_centimos',
        'creada_por',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoReserva::class,
            'total_centimos' => 'integer',
            'costo_cancelacion_centimos' => 'integer',
            'emitida_en' => 'datetime',
            'usada_en' => 'datetime',
            'cancelada_en' => 'datetime',
            'creado_en' => 'datetime',
        ];
    }

    /**
     * Borrar una reserva esta prohibido.
     *
     * Se deja el metodo con error explicito en vez de omitirlo: el mas grave
     * no es que alguien borre, es que borre creyendo que hay una forma
     * soportada de deshacer.
     *
     * @throws \LogicException siempre.
     */
    public function delete(): ?bool
    {
        throw new \LogicException(
            'Una reserva no se borra: pasa por estados. '
            . 'Use App\Services\Viajes\MaquinaDeEstadosReserva para cancelarla.'
        );
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * La fecha concreta de salida.
     *
     * Para un vuelo es obligatorio. Para un hotel es la fecha de entrada. Si
     * el producto no usa fechas (un paquete con fecha abierta), puede venir
     * nulo.
     *
     * @return BelongsTo<Vuelo, $this>
     */
    public function vuelo(): BelongsTo
    {
        return $this->belongsTo(Vuelo::class);
    }

    /** @return BelongsTo<Tarifa, $this> */
    public function tarifa(): BelongsTo
    {
        return $this->belongsTo(Tarifa::class);
    }

    /** @return BelongsTo<Usuario, $this> */
    public function creadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'creada_por');
    }

    /** @return HasMany<ReservaPasajero, $this> */
    public function pasajeros(): HasMany
    {
        return $this->hasMany(ReservaPasajero::class, 'reserva_id');
    }

    /** @return HasMany<Asiento, $this> */
    public function asientos(): HasMany
    {
        return $this->hasMany(Asiento::class, 'reserva_id');
    }

    /** @return HasMany<Pago, $this> */
    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'reserva_id');
    }

    /** @return HasMany<ReservaEvento, $this> */
    public function eventos(): HasMany
    {
        return $this->hasMany(ReservaEvento::class, 'reserva_id')->orderBy('creado_en');
    }

    /**
     * Suma de los pagos de la reserva, en centimos.
     *
     * Se calcula con una consulta y no leyendo la coleccion cargada: si el
     * saldo se calcula sobre una relacion a medio cargar, el resultado depende
     * de que se haya cargado antes, y eso produce saldos distintos en la misma
     * reserva segun por donde se mire.
     */
    public function pagadoCentimos(): int
    {
        return (int) $this->pagos()->where('anulado', false)->sum('monto_centimos');
    }

    public function saldoCentimos(): int
    {
        return $this->total_centimos - $this->pagadoCentimos();
    }

    /** El estado de cuenta, derivado de los totales. */
    public function estadoPago(): EstadoPago
    {
        return EstadoPago::desdeTotales(
            $this->total_centimos,
            $this->pagadoCentimos(),
            $this->estado === EstadoReserva::Cancelada,
        );
    }

    public function estaPagada(): bool
    {
        return $this->estadoPago() === EstadoPago::Pagada;
    }

    /**
     * Un pasaje pagado a medias que nadie ha detectado es la perdida de plata
     * clasica. Este metodo existe para que la pantalla de reservas tenga un
     * filtro listo, no para que alguien lo descubra filtrando a mano.
     */
    public function pagoIncompleto(): bool
    {
        return $this->estadoPago() === EstadoPago::Parcial
            && $this->estado !== EstadoReserva::Cancelada;
    }
}