<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de cambios de estado de la reserva.
 *
 * Esta tabla es la respuesta a "que paso con esta reserva". Sin ella, un estado
 * que se cambio y despues se cambio de nuevo deja de contarse, y la agencia
 * pierde la capacidad de explicar su propia operacion.
 *
 * Se escribe DENTRO de la misma transaccion que el cambio de estado (ver
 * MaquinaDeEstadosReserva): si el historial no se puede escribir, el cambio
 * tampoco.
 *
 * No lleva `updated_at`: un evento es un hecho que paso, no algo que se
 * corrige. Para corregir un evento se escribe otro que lo explique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_eventos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('reserva_id');

            $tabla->string('estado_anterior', 24)->nullable();
            $tabla->string('estado_nuevo', 24);

            // El motivo va texto, no es un catalogo: "el pasajero pidio
            // cambiar la fecha" y "la aerolinea cancelo el vuelo" son hechos
            // distintos y ambos hay que poder contarlos.
            $tabla->string('motivo', 500)->nullable();

            $tabla->integer('costo_centimos')->default(0);
            $tabla->uuid('usuario_id')->nullable();
            $tabla->timestampTz('creado_en');

            $tabla->index(['tenant_id', 'reserva_id', 'creado_en']);
            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
            $tabla->foreign('reserva_id')->references('id')->on('reservas')->cascadeOnDelete();
            $tabla->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_eventos');
    }
};