<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitacora de acceso a datos personales. Ley 19.628.
 *
 * POR QUE EXISTE
 * --------------
 * Un pasajero es una persona natural y su documento de identidad es dato
 * personal. La ley no prohibe tratarlo: prohibe hacerlo sin saber quien lo esta
 * mirando y sin poder demostrar despues quien lo miro.
 *
 * Por eso se registra el ACCESO y no solo el cambio. Ver el documento es el
 * acceso sensible: el dato no se modifica, pero se lee.
 *
 * QUE GUARDA Y QUE NO
 * -------------------
 * Guarda QUIEN, CUANDO, QUE pidio, POR QUE y desde DONDE. NO guarda el valor
 * del dato. Meter el documento en el registro de auditoria seria crear un
 * segundo lugar donde vive el dato personal, sin los controles del primero.
 *
 * `motivo` es obligatorio cuando la accion es leer el documento entero. Se
 * valida en App\Services\Tenancy\AuditorDeAcceso.
 *
 * `ip` y `agente` se guardan porque un registro de acceso sin saber de donde
 * viene no sirve para responder por el acceso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacora_accesos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('usuario_id')->nullable();

            $tabla->string('accion', 64);
            $tabla->string('recurso', 64);
            $tabla->string('recurso_id', 64)->nullable();

            // Por que se accedio al dato. Obligatorio para leer documentos.
            $tabla->string('motivo', 255)->nullable();

            $tabla->string('ip', 45)->nullable();
            $tabla->string('agente', 255)->nullable();

            // Detalle en JSON: ids relacionados, valores que ya son publicos.
            // NUNCA el valor de un dato personal.
            $tabla->jsonb('detalle')->nullable();

            $tabla->timestampTz('creado_en');

            // Consulta principal: "que hizo este agente en este rango".
            $tabla->index(['tenant_id', 'usuario_id', 'creado_en']);
            // Consulta secundaria: "quien accedio a este documento".
            $tabla->index(['tenant_id', 'recurso', 'recurso_id']);
            // La purga por retencion.
            $tabla->index('creado_en');

            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
            $tabla->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora_accesos');
    }
}