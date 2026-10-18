<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las reservas: la venta.
 *
 * UNA RESERVA NO SE BORRA
 * -----------------------
 * No hay `deleted_at` y el modelo `Reserva` prohibe `delete()`. Una venta que
 * se deshace pasa a estado `cancelada`, con su fecha, su motivo y su costo, y
 * queda el rastro en `reserva_eventos`.
 *
 * NOTA SOBRE EL FOLIO
 * -------------------
 * `folio` es unico POR AGENCIA, no global. El indice es compuesto a proposito:
 * dos agencias pueden tener "V-000001" cada una, y el numero del folio no
 * revela nada de la otra.
 *
 * NOTA SOBRE `estado_pago`
 * ------------------------
 * NO EXISTE ESA COLUMNA, y es deliberado. El estado de pago se CALCULA a
 * partir del total y de la suma de pagos (App\Enums\EstadoPago). Guardarlo
 * seria la forma clasica de perder plata: el sistema anunciaria "pagada"
 * cuando falta la mitad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            $tabla->string('folio', 24);

            $tabla->uuid('producto_id');

            // La FECHA CONCRETA. El producto es el trayecto; este es el vuelo
            // del dia que viaja. Obligatorio para productos con fecha.
            $tabla->uuid('vuelo_id')->nullable();

            $tabla->uuid('tarifa_id')->nullable();
            $tabla->uuid('proveedor_id')->nullable();

            $tabla->string('estado', 24)->default('pendiente');

            // De donde salio la venta: mostrador, web, telefono. Sirve para
            // saber que canal trae plata y cual trae reclamos.
            $tabla->string('origen', 32)->default('mostrador');

            $tabla->integer('total_centimos')->default(0);

            $tabla->timestampTz('emitida_en')->nullable();
            $tabla->timestampTz('usada_en')->nullable();
            $tabla->timestampTz('cancelada_en')->nullable();
            $tabla->string('motivo_cancelacion', 500)->nullable();
            $tabla->integer('costo_cancelacion_centimos')->default(0);

            $tabla->uuid('creada_por')->nullable();
            $tabla->timestampTz('creado_en');

            $tabla->unique(['tenant_id', 'folio']);
            $tabla->index(['tenant_id', 'estado']);
            $tabla->index(['tenant_id', 'creado_en']);

            // LA LISTA DE PAGOS INCOMPLETOS.
            //
            // No es un indice sobre una columna, es sobre la cuenta: no se
            // puede indexar un saldo que se calcula. Lo que se indexa es lo
            // que se consulta para DETECTAR el caso: reservas cuyo total no
            // cuadra con la suma de sus pagos.
            $tabla->index(['tenant_id', 'total_centimos']);

            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
            $tabla->foreign('producto_id')->references('id')->on('productos')->restrictOnDelete();
            $tabla->foreign('vuelo_id')->references('id')->on('vuelos')->nullOnDelete();
            $tabla->foreign('creada_por')->references('id')->on('usuarios')->nullOnDelete();
        });

        // Clave foranea diferida desde `asientos`: la tabla de asientos se
        // creo antes que esta, asi que no se pudo declarar alla.
        DB::unprepared(
            'ALTER TABLE public.asientos ADD CONSTRAINT asientos_reserva_fk '
            . 'FOREIGN KEY (reserva_id) REFERENCES public.reservas (id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reservas');
    }
};