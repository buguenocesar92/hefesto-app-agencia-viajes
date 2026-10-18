<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Politicas de nombres y de cancelacion, escritas por la agencia.
 *
 * ================================================================
 * ESTA TABLA ESTA VACIA A PROPOSITO EN UNA INSTALACION NUEVA
 * ================================================================
 * No hay valores por defecto para plazos de cancelacion ni para reglas de
 * nombres. No es una omission: es la decision de no inventar acuerdos
 * comerciales de la agencia.
 *
 * QUE PASA SI NO HAY POLITICA
 * --------------------------
 *  - Sin politica de cancelacion: la cancelacion se RECHAZA con un mensaje
 *    que lo dice. No se asume costo cero.
 *  - Sin politica de nombres: el boleto se arma con el texto del documento,
 *    separado por un espacio, y queda PENDIENTE de revision de un agente. No
 *    se aplica ninguna transformacion.
 *
 * Ver docs/05-CONFIGURACION.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------------------------
        // Politicas de nombres: como se imprime el nombre en el boleto.
        // -------------------------------------------------------------------
        Schema::create('politicas_nombres', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            // Nula cuando es la politica general de la agencia.
            $tabla->uuid('proveedor_id')->nullable();

            $tabla->string('nombre', 120);

            // Separador entre nombres y apellidos en el boleto. La agencia lo
            // elige segun lo que exija la aerolinea; el sistema no decide.
            $tabla->string('separador', 4)->default(' ');

            $tabla->boolean('orden_apellidos_primero')->default(false);

            // Estos tres son criterios de COMPARACION, no de impresion. El
            // texto que sale en el boleto es siempre el del documento.
            $tabla->boolean('exigir_tildes')->default(false);
            $tabla->boolean('exigir_mayusculas')->default(false);
            $tabla->boolean('ignorar_nombres_compuestos')->default(false);

            $tabla->boolean('activa')->default(true);
            $tabla->timestampTz('creado_en');

            $tabla->index(['tenant_id', 'proveedor_id', 'activa']);
            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
            $tabla->foreign('proveedor_id')->references('id')->on('proveedores')->cascadeOnDelete();
        });

        // -------------------------------------------------------------------
        // Politicas de cancelacion: escalones por anticipacion.
        //
        // Cada fila es UN ESCALON. El sistema elige el de mayor anticipacion
        // que no supere los minutos que faltan para la salida.
        //
        // `anticipacion_minima_minutos` se guarda en MINUTOS y no en dias,
        // porque hay tarifas con plazos de horas (por ejemplo, cancelar con
        // menos de 6 horas). Guardarlo en dias obligaria a inventar una regla
        // para las fracciones de dia.
        // -------------------------------------------------------------------
        Schema::create('politicas_cancelacion', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('producto_id');

            $tabla->string('nombre', 120);

            // Desde cuanto de anticipacion aplica este escalon.
            $tabla->integer('anticipacion_minima_minutos');

            // Porcentaje del total a cobrar. De 0 a 100.
            $tabla->integer('porcentaje_costo')->default(0);

            // Monto fijo adicional, en centimos.
            $tabla->integer('costo_fijo_centimos')->default(0);

            // Que pasa con el dinero: "reembolso" o "credito".
            $tabla->string('destino', 24)->default('reembolso');

            $tabla->boolean('activo')->default(true);
            $tabla->timestampTz('creado_en');

            $tabla->index(['tenant_id', 'producto_id', 'activo']);
            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
            $tabla->foreign('producto_id')->references('id')->on('productos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('politicas_cancelacion');
        Schema::dropIfExists('politicas_nombres');
    }
}