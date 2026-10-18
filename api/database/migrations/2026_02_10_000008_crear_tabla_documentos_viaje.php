<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos con los que viajan los pasajeros.
 *
 * ESTA TABLA ES LA FUENTE DEL NOMBRE DEL BOLETO
 * ----------------------------------------------
 * `nombres` y `apellidos` son el texto del documento, tal como esta. El
 * sistema NO los transforma, NO los abrevia, NO les invierte el orden. Si el
 * documento dice "Rodrigo Andrés Pérez", el boleto sale con "Rodrigo Andrés
 * Pérez".
 *
 * Que la correspondencia con lo que el pasajero escribio sea la correcta NO LO
 * DECIDE EL CODIGO: lo decide un agente. `estado_verificacion_nombre` guarda
 * esa decision, junto con `verificado_por` y `verificado_en`.
 *
 * UN PASAJERO PUEDE TENER VARIOS DOCUMENTOS
 * -----------------------------------------
 * Es una fila por documento, no una columna. El pasajero se relaciona con
 * N documentos, y cada viaje de la reserva elige uno. Ver `reserva_pasajeros`.
 *
 * `numero` ES EL DATO MAS SENSIBLE DE ESTA APLICACION
 * --------------------------------------------------
 * Dato personal bajo la Ley 19.628. No sale en ningun listado: el modelo lo
 * tiene en `$hidden` y el Resource lo enmascara. Para verlo entero hay que
 * pedirlo en un endpoint explicito, con motivo y con registro en la bitacora.
 * Ver docs/07.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_viaje', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('pasajero_id');

            $tabla->string('tipo', 32);

            // Dato personal: indice unico DENTRO de la agencia, no global.
            // El mismo numero puede existir en dos agencias sin que se mezclen.
            $tabla->string('numero', 60);

            // El texto del documento. NO se transforma nunca.
            $tabla->string('nombres', 200);
            $tabla->string('apellidos', 200);

            $tabla->date('fecha_emision')->nullable();
            $tabla->date('fecha_vencimiento')->nullable();
            $tabla->date('vencido_en')->nullable();
            $tabla->string('pais_emision', 80)->nullable();

            // El documento que se usa por defecto al proponer uno para un viaje.
            $tabla->boolean('es_principal')->default(false);

            $tabla->string('estado_verificacion_nombre', 24)->default('pendiente');
            $tabla->uuid('verificado_por')->nullable();
            $tabla->timestampTz('verificado_en')->nullable();
            $tabla->string('observacion_verificacion', 500)->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'tipo', 'numero']);
            $tabla->index(['tenant_id', 'pasajero_id']);
            $tabla->index(['tenant_id', 'estado_verificacion_nombre']);
            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
            $tabla->foreign('pasajero_id')->references('id')->on('pasajeros')->cascadeOnDelete();
            $tabla->foreign('verificado_por')->references('id')->on('usuarios')->nullOnDelete();
        });

        // Busqueda por numero de documento, dentro de la agencia. El indice
        // incluye `tenant_id` porque la busqueda siempre se hace con el
        // contexto de agencia puesto, y asi el indice sirve entero.
        DB::unprepared('CREATE INDEX documentos_por_numero ON public.documentos_viaje (tenant_id, numero)');
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_viaje');
    }
};