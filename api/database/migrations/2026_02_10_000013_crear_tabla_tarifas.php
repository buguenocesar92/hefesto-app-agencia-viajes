<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precios por producto y fecha.
 *
 * `precio_centimos` es un entero. Ver App\Services\Pagos\CalculadoraSaldo
 * para por que el dinero se guarda en centimos y no en pesos decimales.
 *
 * `cupos_disponibles` con valor nulo significa "sin control de cupos": el
 * producto no tiene limite. Es un valor explicito, no un cero disfrazado: un
 * cero significaria que esta agotado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarifas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('producto_id');

            // Nulo cuando la tarifa sirve para cualquier fecha del producto.
            $tabla->uuid('vuelo_id')->nullable();

            $tabla->string('nombre_clase', 60)->default('Economica');
            $tabla->integer('precio_centimos');
            $tabla->string('moneda', 3)->default('CLP');

            $tabla->integer('cupos_disponibles')->nullable();
            $tabla->date('vigente_desde')->nullable();
            $tabla->date('vigente_hasta')->nullable();

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'producto_id']);
            $tabla->index(['tenant_id', 'vuelo_id']);
            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
            $tabla->foreign('producto_id')->references('id')->on('productos')->cascadeOnDelete();
            $tabla->foreign('vuelo_id')->references('id')->on('vuelos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarifas');
    }
};