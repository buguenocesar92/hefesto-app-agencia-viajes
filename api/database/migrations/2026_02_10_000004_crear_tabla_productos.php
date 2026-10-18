<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Productos del catalogo: un vuelo comercial, un hotel, un paquete.
 *
 * "UN VUELO ES UN PRODUCTO Y TIENE MUCHAS FECHAS"
 * ----------------------------------------------
 * Aqui esta el producto: el trayecto SCL-SPA con una aerolinea. Las fechas
 * concretas estan en la tabla `vuelos`, una fila por salida.
 *
 * `tipo` decide si la reserva necesita asiento: solo los productos tipo
 * `vuelo` lo usan. Ver App\Enums\TipoProducto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('proveedor_id')->nullable();
            $tabla->string('tipo', 32);
            $tabla->string('codigo', 60)->nullable();
            $tabla->string('nombre', 200);
            $tabla->text('descripcion')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
            $tabla->index(['tenant_id', 'tipo', 'activo']);
            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
            $tabla->foreign('proveedor_id')->references('id')->on('proveedores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};