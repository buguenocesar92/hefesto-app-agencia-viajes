<?php

declare(strict_types=1);

namespace App\Services\Viajes;

use App\Models\PoliticaNombres;
use App\Models\Proveedor;

/**
 * Resuelve que politica de nombres aplica a un producto.
 *
 * La politica se busca por PROVEEDOR, que es como las agencias realmente
 * organizan estas cosas: la aerolinea X exige una cosa y la aerolinea Y otra.
 *
 * Que no haya politica NO es un error. Significa "la agencia no ha definido
 * como se imprimen los nombres con este proveedor", y la consecuencia es que
 * el nombre del boleto queda pendiente de revision humana. El sistema no
 * rellena el hueco con una regla inventada.
 *
 * Ver docs/05-CONFIGURACION.md, seccion "Politica de nombres".
 */
class PoliticaDeNombres
{
    /** Separador cuando la agencia no configuro ninguno: lo mas cercano al documento. */
    public const SEPARADOR_POR_DEFECTO = ' ';

    /**
     * La politica activa de un proveedor, o null si no hay ninguna.
     *
     * Se consulta DENTRO de la peticion, con el contexto de agencia ya fijado
     * por ResolverTenant: la tabla `politicas_nombres` tiene RLS, asi que sin
     * contexto esta consulta lanzaria excepcion en vez de devolver la politica
     * de otra agencia.
     */
    public function paraProveedor(?Proveedor $proveedor): ?PoliticaNombres
    {
        if ($proveedor !== null) {
            $politica = $this->buscarPorProveedor($proveedor->id);

            if ($politica !== null) {
                return $politica;
            }
        }

        return $this->porDefecto();
    }

    public function buscarPorProveedor(string $proveedorId): ?PoliticaNombres
    {
        return PoliticaNombres::query()
            ->where('proveedor_id', $proveedorId)
            ->where('activa', true)
            ->orderByDesc('creado_en')
            ->first();
    }

    /**
     * Politica general de la agencia, sin proveedor asociado.
     *
     * Se usa cuando el producto no tiene proveedor, o cuando el proveedor no
     * tiene politica propia. Sigue siendo una politica que la agencia escribio:
     * si tampoco existe esta, el resultado es null y el nombre queda
     * pendiente de revision.
     */
    public function porDefecto(): ?PoliticaNombres
    {
        return PoliticaNombres::query()
            ->whereNull('proveedor_id')
            ->where('activa', true)
            ->orderByDesc('creado_en')
            ->first();
    }

    /** Separador a usar con la politica dada, con el prudente por defecto. */
    public function separadorCon(?PoliticaNombres $politica): string
    {
        if ($politica === null || $politica->separador === null || $politica->separador === '') {
            return self::SEPARADOR_POR_DEFECTO;
        }

        return $politica->separador;
    }
}