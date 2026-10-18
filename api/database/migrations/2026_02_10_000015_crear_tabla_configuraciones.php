<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parametros de la agencia.
 *
 * `mostrar_documento_en_listados` esta en false y NO debería cambiar a true.
 * Existe la columna a proposito: la decision tiene que estar a la vista y
 * que se tome de forma consciente, no por descuido. Ver docs/07.
 *
 * `dias_aviso_vuelo` es el anticipo con el que la agencia quiere que el sistema
 * avise que viene un vuelo. No es una regla de la industria: es la politica de
 * esta agencia, y por eso es configurable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id')->unique();

            // El dato mas sensible de la aplicacion, con un interruptor que
            // por defecto dice "no".
            $tabla->boolean('mostrar_documento_en_listados')->default(false);

            // Si es true, no se emite una reserva con nombres sin verificar.
            $tabla->boolean('exigir_verificacion_nombre')->default(true);

            // Si es true, leer el documento entero exige un motivo escrito.
            $tabla->boolean('exigir_motivo_lectura_documento')->default(true);

            $tabla->integer('dias_aviso_vuelo')->default(7);
            $tabla->timestampTz('creado_en');

            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
}