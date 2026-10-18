<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Reservas\VerificarNombreRequest;
use App\Http\Resources\ReservaResource;
use App\Models\Reserva;
use App\Models\ReservaPasajero;
use App\Services\Viajes\MaquinaDeEstadosReserva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Verificacion del nombre del boleto. LA FRONTERA DEL PRODUCTO.
 *
 * ================================================================
 * POR QUE ESTO ES UN ENDPOINT APARTE Y NO UN CAMPO EDITABLE
 * ================================================================
 * El nombre del boleto sale del documento con el que viaja el pasajero. El
 * sistema lo arma, lo compara con lo que el pasajero declaro, y lo deja
 * PENDIENTE. Que la correspondencia sea correcta NO LO DECIDE EL CODIGO: lo
 * decide una persona, que puede mirar el documento y llamar a la aerolinea si
 * hace falta.
 *
 * Por eso:
 *  - No hay ningun endpoint que reciba "nombre_boleto" para escribirlo a mano.
 *    El nombre del boleto no se escribe: se arma desde el documento y se
 *    ACEPTA O SE RECHAZA.
 *  - La verificacion exige agente, y guarda quien, cuando y que observo.
 *  - Sin verificar, la reserva no se puede EMITIR. Lo comprueba
 *    MaquinaDeEstadosReserva, no este controlador.
 *
 * Ver docs/01, seccion "El nombre del boleto".
 */
class VerificacionNombreController extends Controller
{
    public function __construct(private readonly MaquinaDeEstadosReserva $maquina) {}

    /**
     * Los pasajeros de una reserva que aun no tienen el nombre verificado.
     *
     * Es la cola de trabajo del agente: lo que tiene que mirar antes de que
     * la reserva pueda emitirse.
     */
    public function pendientes(Reserva $reserva): JsonResponse
    {
        $this->authorize('ver', $reserva);

        $pendientes = ReservaPasajero::query()
            ->where('reserva_id', $reserva->id)
            ->where('estado_verificacion_nombre', '!=', \App\Enums\EstadoVerificacionNombre::Verificado->value)
            ->with('documento')
            ->get();

        return response()->json([
            'reserva_id' => $reserva->id,
            'folio' => $reserva->folio,
            'estado_reserva' => $reserva->estado->value,
            // Con estos datos el agente puede decidir mirando la pantalla.
            // El numero del documento va ENMASCARADO: para verlo entero tiene
            // que usar el endpoint de lectura completa, con motivo.
            'pendientes' => $pendientes->map(static fn (ReservaPasajero $pasajero): array => [
                'id' => $pasajero->id,
                'nombre_boleto' => $pasajero->nombre_boleto,
                'nombre_declarado' => $pasajero->pasajero?->nombre_declarado,
                'documento' => [
                    'id' => $pasajero->documento?->id,
                    'tipo' => $pasajero->documento?->tipo->value,
                    'numero_enmascarado' => $pasajero->documento?->numeroEnmascarado(
                        (int) config('viajes.nombres.mascara_visibles')
                    ),
                    'nombres' => $pasajero->documento?->nombres,
                    'apellidos' => $pasajero->documento?->apellidos,
                ],
                'estado_verificacion_nombre' => $pasajero->estado_verificacion_nombre->value,
                'observacion' => $pasajero->observacion_verificacion,
                'puede_emitir' => $pasajero->puedeEmitir(),
            ])->values()->all(),
            // Recordatorio explicito de quien decide.
            'nota' => 'La correspondencia entre el nombre del boleto y el documento la revisa un agente. El sistema solo avisa.',
        ]);
    }

    /**
     * El agente marca el nombre como verificado o rechazado.
     */
    public function store(VerificarNombreRequest $peticion, ReservaPasajero $pasajeroReserva): JsonResponse
    {
        $reserva = Reserva::query()->findOrFail($pasajeroReserva->reserva_id);

        $this->authorize('verificarNombre', $reserva);

        $estado = \App\Enums\EstadoVerificacionNombre::from($peticion->validated('estado'));
        $observacion = $peticion->validated('observacion');

        if ($estado === \App\Enums\EstadoVerificacionNombre::Rechazado && ($observacion === null || trim($observacion) === '')) {
            // Rechazar sin decir por que deja al pasajero y al siguiente
            // agente sin informacion para resolverlo.
            return response()->json([
                'mensaje' => 'Para rechazar un nombre hay que indicar que se encontro.',
            ], 422);
        }

        $this->maquina->verificarNombre($pasajeroReserva, $estado, $peticion->user(), $observacion);

        return response()->json(new ReservaResource($reserva->refresh()->load('pasajeros.documento')));
    }
}