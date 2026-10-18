<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Pasajeros\StoreDocumentoRequest;
use App\Http\Requests\Pasajeros\StorePasajeroRequest;
use App\Http\Resources\DocumentoViajeResource;
use App\Http\Resources\PasajeroResource;
use App\Models\DocumentoViaje;
use App\Models\Pasajero;
use App\Services\Tenancy\AuditorDeAcceso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pasajeros y sus documentos de viaje.
 *
 * AQUI VIVE EL DATO MAS SENSIBLE DE LA APLICACION
 * -----------------------------------------------
 * El documento de identidad de un pasajero es dato personal bajo la Ley
 * 19.628. Por eso:
 *
 *  - Los LISTADOS devuelven el numero ENMASCARADO (ver
 *    DocumentoViajeResource). El numero completo no sale nunca de ahi.
 *  - Ver el numero ENTERO exige un endpoint explicito (`documento`), un motivo
 *    escrito y deja un registro en la bitacora con quien, cuando y por que.
 *  - Cambiar un estado de cuenta no da permiso para leer documentos: son
 *    permisos separados.
 *
 * Ver docs/07-SEGURIDAD-Y-LEY-19628.md.
 */
class PasajeroController extends Controller
{
    public function __construct(private readonly AuditorDeAcceso $auditor) {}

    public function index(Request $peticion): AnonymousResourceCollection
    {
        $filtros = $peticion->validate([
            'buscar' => ['nullable', 'string', 'max:60'],
        ]);

        $consulta = Pasajero::query()->with('documentos');

        if (! empty($filtros['buscar'])) {
            $termino = '%' . $filtros['buscar'] . '%';

            // Se busca por nombre declarado. El nombre del BOLETO esta en otra
            // tabla y con otro proposito.
            $consulta->where('nombre_declarado', 'ilike', $termino);
        }

        return PasajeroResource::collection($consulta->orderBy('nombre_declarado')->paginate(25));
    }

    public function show(Pasajero $pasajero): PasajeroResource
    {
        $this->authorize('ver', $pasajero);

        return new PasajeroResource($pasajero->load('documentos'));
    }

    public function store(StorePasajeroRequest $peticion): PasajeroResource
    {
        $this->authorize('crear', Pasajero::class);

        $pasajero = Pasajero::query()->create([
            'tenant_id' => $peticion->user()->tenant_id,
            ...$peticion->validated(),
        ]);

        $this->auditor->registrar('pasajero.creado', 'pasajero', $pasajero->id);

        return new PasajeroResource($pasajero->load('documentos'));
    }

    /**
     * Alta de un documento de viaje.
     *
     * UN PASAJERO PUEDE TENER VARIOS. Esto NO es un "actualizar documento":
     * se llama igual aunque el pasajero ya tenga uno vigente. La renovacion de
     * pasaporte crea un documento nuevo y deja el viejo como historial.
     */
    public function storeDocumento(StoreDocumentoRequest $peticion, Pasajero $pasajero): DocumentoViajeResource
    {
        $this->authorize('actualizar', $pasajero);

        $documento = DocumentoViaje::query()->create([
            'tenant_id' => $peticion->user()->tenant_id,
            'pasajero_id' => $pasajero->id,
            ...$peticion->validated(),
        ]);

        return new DocumentoViajeResource($documento);
    }

    /**
     * LECTURA DEL DOCUMENTO COMPLETO.
     *
     * Este es el unico endpoint que devuelve el numero entero, y es a
     * proposito: leer el documento es el acceso sensible, asi que es explicito,
     * pide motivo y queda en la bitacora.
     *
     * Si no viene motivo y la agencia lo exige (configuracion por defecto), se
     * responde 422 y no se entrega nada.
     */
    public function documento(Pasajero $pasajero, DocumentoViaje $documento): DocumentoViajeResource
    {
        $this->authorize('leerDocumentoCompleto', $pasajero);

        // El documento TIENE que ser de este pasajero. Sin esta comprobacion,
        // un `documento_id` de otro pasajero devolveria el documento: el
        // route-model binding lo resuelve, pero no sabe de quien es.
        if ($documento->pasajero_id !== $pasajero->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $motivo = request()->header('X-Motivo-Consulta');

        $configuracion = $pasajero->agencia?->configuracion();

        $exigeMotivo = $configuracion?->exigir_motivo_lectura_documento
            ?? (bool) config('seguridad.datos_personales.exigir_motivo_para_leer_documento');

        if ($exigeMotivo && ($motivo === null || trim($motivo) === '')) {
            return response()->json([
                'mensaje' => 'Para ver el documento completo hay que indicar el motivo de la consulta.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // La escritura en la bitacora la hace el middleware AuditarAcceso,
        // que ya reviso la ruta. Aqui se agrega el contexto que el middleware
        // no tiene: que el pasajero se esta mostrando.
        $this->auditor->registrar(
            'documento.leido',
            'documento_viaje',
            $documento->id,
            $motivo,
            ['pasajero_id' => $pasajero->id],
        );

        // Se quita el $hidden del modelo SOLO para esta respuesta, y solo para
        // este endpoint. Es la excepcion conscious, no el camino normal.
        $documento->makeVisible('numero');

        return new DocumentoViajeResource($documento);
    }
}