<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fechas concretas de salida de un producto de tipo vuelo.
 *
 * Cada fila es UNA FECHA. Del producto "SCL-SPA, aerolinea X" salen muchas
 * filas en esta tabla: el vuelo del lunes, el del martes, el de marzo.
 *
 * Cada una tiene su PROPIA tabla de asientos. Por eso la reserva apunta a
 * `vuelo_id` y no a `producto_id`: si apuntara al producto, dos personas que
 * viajan el mismo trayecto en fechas distintas compartirian asientos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vuelos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('producto_id');
            $tabla->string('codigo_vuelo', 32);

            // Se guardan como IATA de tres letras, en mayusculas. No se inventa
            // un catalogo de ciudades: el codigo es el que escribe la agencia.
            $tabla->string('origen', 3);
            $tabla->string('destino', 3);

            // Horarios en hora de Chile (config/app.php, timezone).
            $tabla->timestampTz('salida_en');
            $tabla->timestampTz('llegada_en');
            $tabla->string('aeronave', 40)->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'producto_id']);
            $tabla->index(['tenant_id', 'salida_en']);
            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
            $tabla->foreign('producto_id')->references('id')->on('productos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vuelos');
    }
};