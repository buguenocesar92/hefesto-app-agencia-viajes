<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos y devoluciones.
 *
 * LOS PAGOS SE REGISTRAN, NO SE EDITAN
 * -------------------------------------
 * Un pago no se modifica ni se borra: se anula (`anulado = true`, con motivo y
 * fecha) y se vuelve a registrar. Asi el estado de cuenta siempre se puede
 * reconstruir mirando la historia, que es justo lo que hace falta cuando un
 * pasajero reclama.
 *
 * `monto_centimos` es un entero. Un pago parcial de 45.000 pesos son 4.500.000
 * de centimos. Nunca se guarda como decimal en coma flotante: la suma de
 * flotantes produce diferencias de un peso, y esa diferencia es el descuadre
 * que nadie encuentra despues.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('reserva_id');

            $tabla->integer('monto_centimos');

            $tabla->string('metodo', 32)->default('transferencia');
            $tabla->string('referencia', 120)->nullable();

            $tabla->boolean('anulado')->default(false);
            $tabla->string('anulado_motivo', 500)->nullable();
            $tabla->timestampTz('anulado_en')->nullable();

            $tabla->uuid('registrado_por')->nullable();
            $tabla->timestampTz('creado_en');

            // El indice que sostiene el calculo del saldo: sumar los pagos NO
            // anulados de una reserva tiene que ser barato, porque la pantalla
            // de reservas lo hace en cada fila.
            $tabla->index(['tenant_id', 'reserva_id', 'anulado']);
            $tabla->index(['tenant_id', 'creado_en']);

            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
            $tabla->foreign('reserva_id')->references('id')->on('reservas')->cascadeOnDelete();
            $tabla->foreign('registrado_por')->references('id')->on('usuarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
}