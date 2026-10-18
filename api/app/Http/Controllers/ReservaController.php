<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ReservaResource;
use App\Http\Requests\Reservas\CambiarEstadoRequest;
use App\Http\Requests\Reservas\StoreReservaRequest;
use App\Enums\EstadoReserva;
use App\Models\Producto;
use App\Models\Reserva;
use App\Services\Tenancy\AuditorDeAcceso;
use App\Services\Viajes\ConstruccionBoleto;
use App\Services\Viajes\MaquinaDeEstadosReserva;
use App\Services\Viajes\PoliticaDeNombres;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reservas: la venta.
 *
 * CREAR UNA RESERVA ES LA OPERACION MAS IMPORTANTE DE ESTA APLICACION
 * ---------------------------------------------------------------
 * Y lo que hace no es "crear una fila": es decidir, pasajero por pasajero,
 * CON QUE DOCUMENTO se viaja y ARMAR EL NOMBRE DEL BOLETO desde ese documento.
 *
 * El nombre que se guarda en `reserva_pasajeros.nombre_boleto` sale SIEMPRE de
 * `documentos_viaje.nombres` y `.apellidos`. Nunca de
 * `pasajeros.nombre_declarado`, que es solo como el pasajero se escribe a si
 * mismo. Ver App\Services\Viajes\ConstruccionBoleto.
 *
 * La reserva nace SIEMPRE en estado `pendiente` y con los nombres en estado
 * `pendiente` de verificacion. Emitir despues exige que un agente haya
 * verificado cada nombre. Ese es el control que evita que un pasajero llegue al
 * aeropuerto sin poder viajar.
 */
class ReservaController extends Controller
{
    public function __construct(
        private readonly MaquinaDeEstadosReserva $maquina,
        private readonly ConstruccionBoleto $boleto,
        private readonly PoliticaDeNombres $politicas,
        private readonly AuditorDeAcceso $auditor,
    ) {}

    /**
     * Listado de reservas, con filtro de estado y de pago incompleto.
     */
    public function index(Request $peticion): AnonymousResourceCollection
    {
        $filtros = $peticion->validate([
            'estado' => ['nullable', 'string'],
            'paginas_incompletas' => ['nullable', 'boolean'],
            'buscar' => ['nullable', 'string', 'max:60'],
        ]);

        $consulta = Reserva::query()->with(['producto', 'vuelo', 'pasajeros.asiento']);

        if (isset($filtros['estado'])) {
            $consulta->where('estado', $filtros['estado']);
        }

        if (! empty($filtros['paginas_incompletas'])) {
            // El filtro del problema clasico. Se calcula en memoria porque el
            // saldo depende de la suma de pagos y no se puede indexar.
            $consulta->where('estado', '!=', EstadoReserva::Cancelada->value)
                ->get()
                ->filter(fn (Reserva $reserva): bool => $reserva->pagoIncompleto());
        }

        if (! empty($filtros['buscar'])) {
            $termino = '%' . $filtros['buscar'] . '%';
            $consulta->where(function ($q) use ($termino): void {
                $q->where('folio', 'ilike', $termino)
                    ->orWhereHas('pasajeros', fn ($sub) => $sub->where('nombre_boleto', 'ilike', $termino));
            });
        }

        return ReservaResource::collection(
            $consulta->orderByDesc('creado_en')->paginate(25)
        );
    }

    public function show(Reserva $reserva): ReservaResource
    {
        $this->autorizar('ver', $reserva);

        return new ReservaResource($reserva->load(['producto', 'vuelo', 'pasajeros.documento', 'asientos']));
    }

    /**
     * Crea una reserva.
     *
     * TODO DENTRO DE UNA TRANSACCION
     * -------------------------------
     * La reserva, sus pasajeros, sus nombres de boleto y sus asientos se
     * escriben juntos o no se escribe nada. Una reserva sin pasajeros, o con un
     * pasajero sin nombre, es peor que una reserva rechazada: queda en pantalla
     * como si estuviera bien.
     */
    public function store(StoreReservaRequest $peticion): ReservaResource
    {
        $datos = $peticion->validated();
        $usuario = $peticion->user();

        $reserva = DB::transaction(function () use ($datos, $usuario, $peticion) {
            $producto = Producto::query()->findOrFail($datos['producto_id']);

            // La politica de nombres se resuelve UNA vez para toda la reserva,
            // segun el proveedor del producto. Por eso el nombre del boleto se
            // arma igual para todos los pasajeros: es lo que la agencia
            // configuro para ese proveedor.
            $politica = $this->politicas->paraProveedor($producto->proveedor);

            $reserva = new Reserva([
                'tenant_id' => $usuario->tenant_id,
                'folio' => $this->siguienteFolio($producto),
                'producto_id' => $producto->id,
                'vuelo_id' => $datos['vuelo_id'] ?? null,
                'tarifa_id' => $datos['tarifa_id'] ?? null,
                'proveedor_id' => $producto->proveedor_id,
                'estado' => EstadoReserva::Pendiente,
                'origen' => $datos['origen'],
                'total_centimos' => $datos['total_centimos'] ?? $this->totalDesdeTarifa($datos),
                'creada_por' => $usuario->id,
                'creado_en' => now(),
            ]);

            $reserva->save();

            foreach ($datos['pasajeros'] as $entrada) {
                $this->agregarPasajero($reserva, $entrada, $politica, $peticion);
            }

            $this->auditor->registrar('reserva.creada', 'reserva', $reserva->id);

            return $reserva;
        });

        return new ReservaResource($reserva->load(['producto', 'vuelo', 'pasajeros.asiento']));
    }

    /**
     * Cambia el estado de la reserva.
     *
     * Cancelar NO borra: pasa el estado y deja el rastro. Ver
     * MaquinaDeEstadosReserva.
     */
    public function cambiarEstado(CambiarEstadoRequest $peticion, Reserva $reserva): ReservaResource
    {
        $this->autorizar('actualizar', $reserva);

        $destino = EstadoReserva::from($peticion->validated('estado'));

        try {
            $reserva = $this->maquina->transicionar(
                $reserva,
                $destino,
                $peticion->user(),
                $peticion->validated('motivo'),
            );
        } catch (RuntimeException $excepcion) {
            // 422, no 500: es un error de reglas de negocio, no una falla. El
            // mensaje dice que transiciones SI son posibles, para que el
            // operador no tenga que adivinar.
            return response()->json(['mensaje' => $excepcion->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new ReservaResource($reserva);
    }

    /**
     * Historial de cambios de estado.
     */
    public function eventos(Reserva $reserva): AnonymousResourceCollection
    {
        $this->autorizar('ver', $reserva);

        return AnonymousResourceCollection::make(
            $reserva->eventos()->with('usuario')->get()->map(static fn ($evento): array => [
                'id' => $evento->id,
                'de' => $evento->estado_anterior?->etiqueta(),
                'a' => $evento->estado_nuevo->etiqueta(),
                'motivo' => $evento->motivo,
                'costo' => $evento->costo_centimos,
                'usuario' => $evento->usuario?->nombre,
                'creado_en' => $evento->creado_en->format('d-m-Y H:i'),
            ])
        );
    }

    /**
     * Agrega un pasajero a la reserva y le arma el nombre del boleto desde su
     * documento.
     *
     * EL NOMBRE DEL BOLETO NUNCA VIENE DE `nombre_declarado`
     * -------------------------------------------------
     * Si el pasajero escribio "Rodrigo" y el pasaporte dice "Rodrigo Andrés
     * Pérez", el boleto dice "Rodrigo Andrés Pérez". Si estan escritos
     * distinto, el boleto va con el del documento y queda PENDIENTE de que un
     * agente confirme la correspondencia.
     */
    private function agregarPasajero(
        Reserva $reserva,
        array $entrada,
        ?\App\Models\PoliticaNombres $politica,
        StoreReservaRequest $peticion,
    ): void {
        $documento = \App\Models\DocumentoViaje::query()->findOrFail($entrada['documento_viaje_id']);
        $pasajero = \App\Models\Pasajero::query()->findOrFail($entrada['pasajero_id']);

        $armado = $this->boleto->nombreDelBoleto($documento, $politica);

        $revision = $this->boleto->revisar($documento, $pasajero->nombre_declarado, $politica);

        $reserva->pasajeros()->create([
            'tenant_id' => $reserva->tenant_id,
            'pasajero_id' => $pasajero->id,
            'documento_viaje_id' => $documento->id,

            // De aqui sale el nombre del boleto. Del documento.
            'nombre_boleto' => $armado['nombre'],

            // Nace pendiente, SIEMPRE. La automatica compara y avisa; no
            // verifica.
            'estado_verificacion_nombre' => $revision['estado'],
            'observacion_verificacion' => $revision['diferencias'] === []
                ? null
                : implode(' | ', $revision['diferencias']),
            'es_contacto' => (bool) ($entrada['es_contacto'] ?? false),
        ]);
    }

    /**
     * El total sale de la tarifa si no viene explicito.
     *
     * Si no hay tarifa, el total queda en cero y la reserva nace impaga: es
     * visible en el estado de cuenta, no escondido. Preferible a inventar un
     * precio.
     */
    private function totalDesdeTarifa(array $datos): int
    {
        if (empty($datos['tarifa_id'])) {
            return 0;
        }

        $tarifa = \App\Models\Tarifa::query()->find($datos['tarifa_id']);

        return $tarifa?->precio_centimos ?? 0;
    }

    /**
     * Folio correlativo POR AGENCIA.
     *
     * Se cuenta sobre las reservas de la propia agencia, asi que dos agencias
     * pueden tener "V-000001" las dos sin que el folio diga nada de la otra.
     */
    private function siguienteFolio(Producto $producto): string
    {
        $prefijo = 'V';

        $ultimo = Reserva::query()
            ->where('folio', 'like', $prefijo . '-%')
            ->orderByDesc('folio')
            ->value('folio');

        $siguiente = $ultimo === null ? 1 : ((int) substr($ultimo, strlen($prefijo) + 1)) + 1;

        return $prefijo . '-' . str_pad((string) $siguiente, 6, '0', STR_PAD_LEFT);
    }

    private function autorizar(string $habilidad, Reserva $reserva): void
    {
        $this->authorize($habilidad, $reserva);
    }
}