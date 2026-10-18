<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pasajeros dentro de una reserva: la bisagra entre la persona y el viaje.
 *
 * POR QUE EXISTE UNA TABLA INTERMEDIA
 * ----------------------------------
 * Porque "el pasajero" y "el viaje" no son lo mismo. La misma persona viaja
 * muchas veces con la agencia, y en cada viaje lleva un documento concreto.
 *
 * `documento_viaje_id` es el documento de ESTE viaje, no "el documento del
 * pasajero". Por eso un mismo pasajero puede tener reservas apuntando a
 * documentos distintos: renovó el pasaporte y el siguiente viaje usa el nuevo.
 *
 * `nombre_boleto` ES UNA COPIA DEL NOMBRE DEL DOCUMENTO
 * ---------------------------------------------------
 * Se llena SIEMPRE con `documentos_viaje.nombres` y `apellidos`, nunca con
 * `pasajeros.nombre_declarado`.
 *
 * Es una copia y no una referencia por una razon concreta: el boleto ya salio.
 * Si manana el pasajero actualiza su documento, el boleto emitido ayer tiene
 * que seguir diciendo lo que decia ayer. Con una referencia sola, el boleto de
 * ayer apareceria hoy con un nombre que nunca se compro.
 *
 * `estado_verificacion_nombre` es el control que impide emitir con un nombre
 * sin revisar. Ver App\Services\Viajes\MaquinaDeEstadosReserva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_pasajeros', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('reserva_id');
            $tabla->uuid('pasajero_id');

            // El documento con el que viaja en esta reserva.
            $tabla->uuid('documento_viaje_id');

            // COPIA del nombre del documento en el momento de armar el boleto.
            $tabla->string('nombre_boleto', 400);

            $tabla->string('estado_verificacion_nombre', 24)->default('pendiente');
            $tabla->uuid('verificado_por')->nullable();
            $tabla->timestampTz('verificado_en')->nullable();
            $tabla->string('observacion_verificacion', 500)->nullable();

            // A quien se llama en caso de cambio de vuelo.
            $tabla->boolean('es_contacto')->default(false);

            $tabla->unique(['reserva_id', 'pasajero_id']);
            $tabla->index(['tenant_id', 'estado_verificacion_nombre']);
            $tabla->index(['tenant_id', 'documento_viaje_id']);

            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
            $tabla->foreign('reserva_id')->references('id')->on('reservas')->cascadeOnDelete();
            $tabla->foreign('pasajero_id')->references('id')->on('pasajeros')->restrictOnDelete();
            $tabla->foreign('documento_viaje_id')->references('id')->on('documentos_viaje')->restrictOnDelete();
            $tabla->foreign('verificado_por')->references('id')->on('usuarios')->nullOnDelete();
        });

        // Clave foranea diferida desde `asientos`.
        DB::unprepared(
            'ALTER TABLE public.asientos ADD CONSTRAINT asientos_pasajero_fk '
            . 'FOREIGN KEY (reserva_pasajero_id) REFERENCES public.reserva_pasajeros (id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_pasajeros');
    }
}