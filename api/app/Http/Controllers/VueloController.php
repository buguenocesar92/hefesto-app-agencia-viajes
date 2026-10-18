<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Agencia;
use App\Models\Producto;
use App\Models\Vuelo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Vuelos y productos: el catalogo.
 *
 * Aqui esta el unico punto donde se lee la tabla `agencias` sin contexto de
 * RLS, y solo a proposito: la pantalla de entrada necesita saber que la agencia
 * existe ANTES de que haya sesion.
 *
 * Por eso no lee `agencias` crudo sino la vista `vw_agencias_publicas`, que
 * expone solo tres columnas. Ver docs/03.
 */
class VueloController extends Controller
{
    /**
     * Agencias activas, para la pantalla de entrada.
     *
     * Solo `slug`, `nombre` y `activo`. No expone RUT, razon social ni correo:
     * antes de autenticarse, eso no le sirve a nadie y si se filtra no sirve de
     * nada mas que para dar pistas al atacante.
     */
    public function agenciasPublicas(): JsonResponse
    {
        $agencias = DB::table('vw_agencias_publicas')
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['slug', 'nombre']);

        return response()->json(['agencias' => $agencias]);
    }

    /**
     * Vuelos con plazas disponibles.
     */
    public function index(): AnonymousResourceCollection
    {
        $vuelos = Vuelo::query()
            ->with(['producto.proveedor', 'tarifas'])
            ->where('activo', true)
            ->where('salida_en', '>=', now())
            ->orderBy('salida_en')
            ->limit(100)
            ->get();

        return AnonymousResourceCollection::make($vuelos->map(static fn (Vuelo $vuelo): array => [
            'id' => $vuelo->id,
            'codigo_vuelo' => $vuelo->codigo_vuelo,
            'tramo' => $vuelo->tramo(),
            'salida_en' => $vuelo->salida_en->format('d-m-Y H:i'),
            'llegada_en' => $vuelo->llegada_en->format('d-m-Y H:i'),
            'producto' => [
                'id' => $vuelo->producto?->id,
                'nombre' => $vuelo->producto?->nombre,
                'tipo' => $vuelo->producto?->tipo->value,
            ],
            'tarifas' => $vuelo->tarifas->map(static fn ($tarifa): array => [
                'id' => $tarifa->id,
                'clase' => $tarifa->nombre_clase,
                'precio' => $tarifa->precio_centimos,
                'moneda' => $tarifa->moneda,
                'cupos' => $tarifa->cupos_disponibles,
            ])->values()->all(),
        ]));
    }

    public function show(Vuelo $vuelo): JsonResponse
    {
        $this->authorize('ver', $vuelo);

        return response()->json([
            'id' => $vuelo->id,
            'codigo_vuelo' => $vuelo->codigo_vuelo,
            'tramo' => $vuelo->tramo(),
            'origen' => $vuelo->origen,
            'destino' => $vuelo->destino,
            'salida_en' => $vuelo->salida_en->format('d-m-Y H:i'),
            'llegada_en' => $vuelo->llegada_en->format('d-m-Y H:i'),
            'aeronave' => $vuelo->aeronave,
            'producto' => $vuelo->producto?->nombre,
            'proveedor' => $vuelo->producto?->proveedor?->nombre,
        ]);
    }

    /**
     * Productos del catalogo.
     */
    public function productos(): AnonymousResourceCollection
    {
        $productos = Producto::query()
            ->with('proveedor')
            ->where('activo', true)
            ->orderBy('nombre')
            ->limit(200)
            ->get();

        return AnonymousResourceCollection::make($productos->map(static fn (Producto $producto): array => [
            'id' => $producto->id,
            'tipo' => $producto->tipo->value,
            'tipo_etiqueta' => $producto->tipo->etiqueta(),
            'nombre' => $producto->nombre,
            'proveedor' => $producto->proveedor?->nombre,
            'usa_asientos' => $producto->usaAsientos(),
        ]));
    }
}