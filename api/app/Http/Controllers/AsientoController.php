<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AsientoResource;
use App\Models\Asiento;
use App\Models\Vuelo;
use App\Services\Tenancy\AuditorDeAcceso;
use App\Services\Viajes\ServicioAsientos;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asientos.
 *
 * El mapa de un vuelo se arma LEYENDO LAS FILAS, no preguntando a Redis.
 * Lo que se muestra en pantalla es lo que la base de datos garantiza.
 *
 * Tomar un asiento delega en ServicioAsientos, que es donde esta la logica de
 * concurrencia. Ver ese servicio y docs/01, seccion "Bloqueo de asientos".
 */
class AsientoController extends Controller
{
    public function __construct(
        private readonly ServicioAsientos $asientos,
        private readonly AuditorDeAcceso $auditor,
    ) {}

    /**
     * Mapa de asientos de un vuelo.
     */
    public function index(Vuelo $vuelo): AnonymousResourceCollection
    {
        $this->authorize('ver', $vuelo);

        $asientos = Asiento::query()
            ->where('vuelo_id', $vuelo->id)
            ->orderBy('fila_orden')
            ->orderByRaw('columna_numero NULLS LAST')
            ->get();

        return AsientoResource::collection($asientos);
    }

    /**
     * Toma un asiento para una reserva.
     *
     * Si dos compras piden el mismo asiento al mismo tiempo, UNA falla con 409
     * y la otra sale con el asiento. El que falla recibe un mensaje que dice
     * que el seat fue tomado por otra compra, no un error generico: el agente
     * tiene que elegir otro y necesita saber por que.
     */
    public function bloquear(Request $peticion, Vuelo $vuelo, string $numero): AsientoResource
    {
        $datos = $peticion->validate([
            'reserva_id' => ['required', 'uuid'],
            'reserva_pasajero_id' => ['nullable', 'uuid'],
            'bloquear' => ['nullable', 'boolean'],
        ]);

        $this->authorize('actualizar', $vuelo);

        try {
            $asiento = $this->asientos->tomar(
                vueloId: $vuelo->id,
                numero: $numero,
                reservaId: $datos['reserva_id'],
                reservaPasajeroId: $datos['reserva_pasajero_id'] ?? null,
                usuario: $peticion->user(),
                bloquear: (bool) ($datos['bloquear'] ?? false),
            );
        } catch (RuntimeException $excepcion) {
            // 409: el asiento esta ocupado o no existe. Es un conflicto con el
            // estado actual, no un error de validacion (400/422) ni una falla
            // del servidor (500).
            return response()->json([
                'mensaje' => $excepcion->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        $this->auditor->registrar('asiento.bloqueado', 'asiento', $asiento->id, null, [
            'vuelo_id' => $vuelo->id,
            'numero' => $numero,
        ]);

        return new AsientoResource($asiento);
    }

    /**
     * Libera un asiento. Vuelve a quedar disponible y queda el rastro.
     */
    public function liberar(Asiento $asiento): AsientoResource
    {
        $this->authorize('actualizar', $asiento->vuelo);

        try {
            $asiento = $this->asientos->liberar($asiento->id, 'Liberado desde la pantalla de asientos');
        } catch (RuntimeException $excepcion) {
            return response()->json(['mensaje' => $excepcion->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->auditor->registrar('asiento.liberado', 'asiento', $asiento->id);

        return new AsientoResource($asiento);
    }
}