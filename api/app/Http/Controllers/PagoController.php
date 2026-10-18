<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Pagos\StorePagoRequest;
use App\Http\Resources\PagoResource;
use App\Http\Resources\ReservaResource;
use App\Models\Reserva;
use App\Services\Pagos\CalculadoraSaldo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pagos y estado de cuenta.
 *
 * UN PASAJE PAGADO A MEDIAS Y NO DETECTADO ES LA PERDIDA DE PLATA CLASICA
 * ----------------------------------------------------------------------
 * Por eso el estado de cuenta se devuelve SIEMPRE, incluso sin pedirlo, y por
 * eso el saldo se calcula y no se guarda. Ver App\Services\Pagos\CalculadoraSaldo.
 */
class PagoController extends Controller
{
    public function __construct(private readonly CalculadoraSaldo $saldo) {}

    public function index(Reserva $reserva): AnonymousResourceCollection
    {
        $this->authorize('ver', $reserva);

        return PagoResource::collection($reserva->pagos()->orderBy('creado_en')->get());
    }

    /**
     * Estado de cuenta de una reserva.
     */
    public function estadoDeCuenta(Reserva $reserva): JsonResponse
    {
        $this->authorize('ver', $reserva);

        $cuenta = $this->saldo->estadoDeCuenta($reserva);

        return response()->json([
            'reserva_id' => $reserva->id,
            'folio' => $reserva->folio,
            'total' => $cuenta['total_centimos'],
            'pagado' => $cuenta['pagado_centimos'],
            'saldo' => $cuenta['saldo_centimos'],
            'estado' => $cuenta['estado']->value,
            'estado_etiqueta' => $cuenta['estado']->etiqueta(),
            'pagos' => PagoResource::collection($cuenta['pagos']),
        ]);
    }

    /**
     * Registra un pago, que puede ser PARCIAL.
     *
     * Un pago parcial es legitimo y corriente: la agencia acepta una cuota y
     * el pasaje queda pendiente. Lo que no es legitimo es que el sistema no lo
     * muestre, y por eso la respuesta devuelve el estado de cuenta completo
     * despues de registrar.
     */
    public function store(StorePagoRequest $peticion, Reserva $reserva): JsonResponse
    {
        $this->authorize('pagar', $reserva);

        try {
            $pago = $this->saldo->registrar(
                $reserva,
                (int) $peticion->validated('monto_centimos'),
                $peticion->validated('metodo'),
                $peticion->validated('referencia'),
                $peticion->user(),
            );
        } catch (RuntimeException $excepcion) {
            return response()->json(['mensaje' => $excepcion->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cuenta = $this->saldo->estadoDeCuenta($reserva->refresh());

        return response()->json([
            'pago' => (new PagoResource($pago))->resolve($peticion),
            'estado_de_cuenta' => [
                'total' => $cuenta['total_centimos'],
                'pagado' => $cuenta['pagado_centimos'],
                'saldo' => $cuenta['saldo_centimos'],
                'estado' => $cuenta['estado']->value,
                'estado_etiqueta' => $cuenta['estado']->etiqueta(),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Anula un pago. No lo borra: queda con su motivo y su fecha.
     */
    public function anular(
        \Illuminate\Http\Request $peticion,
        Reserva $reserva,
        \App\Models\Pago $pago
    ): JsonResponse {
        $this->authorize('pagar', $reserva);

        $datos = $peticion->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        if ($pago->reserva_id !== $reserva->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        try {
            $pago = $this->saldo->anular($pago, $datos['motivo'], $peticion->user());
        } catch (RuntimeException $excepcion) {
            return response()->json(['mensaje' => $excepcion->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(new PagoResource($pago));
    }

    /**
     * Listado de reservas con pago incompleto.
     *
     * Es la pantalla que existe para que la plata no se pierda sin que nadie lo
     * note: todo lo que esta pagado a medias y no cancelado, ordenado por
     * antiguedad.
     */
    public function incompletos(): AnonymousResourceCollection
    {
        $this->authorize('ver', Reserva::class);

        $reservas = Reserva::query()
            ->with(['producto', 'vuelo', 'pasajeros.asiento'])
            ->where('estado', '!=', \App\Enums\EstadoReserva::Cancelada->value)
            ->get()
            ->filter(fn (Reserva $reserva): bool => $reserva->pagoIncompleto())
            ->sortBy('creado_en')
            ->values();

        return ReservaResource::collection($reservas);
    }
}