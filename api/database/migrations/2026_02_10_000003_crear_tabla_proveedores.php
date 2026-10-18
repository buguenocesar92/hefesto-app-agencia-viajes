<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proveedores: aerolineas, hoteles y mayoristas.
 *
 * Lleva `tenant_id` y NO se comparte entre agencias: dos agencias pueden
 * tener la misma aerolinea como proveedor, con condiciones comerciales
 * distintas, y mezclar esos datos seria mostrarle a una los precios de la
 * otra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('tipo', 32)->default('aerolinea');
            $tabla->string('codigo', 40)->nullable();
            $tabla->string('nombre', 160);
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'nombre']);
            $tabla->index(['tenant_id', 'activo']);
            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};