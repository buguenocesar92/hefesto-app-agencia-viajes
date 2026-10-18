<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asientos de un vuelo.
 *
 * ================================================================
 * ESTA MIGRACION INSTALA LA GARANTIA DE QUE UN ASIENTO NO SE VENDE DOS VECES
 * ================================================================
 * La garantia NO es el candado de Redis ni el `if ($asiento->libre)` de PHP.
 * Es esta restriccion de exclusion:
 *
 *   ALTER TABLE asientos ADD CONSTRAINT asientos_no_duplicados
 *     EXCLUDE USING gist (vuelo_id WITH =, numero WITH =)
 *     WHERE (estado IN ('bloqueado', 'asignado'));
 *
 * QUE HACE EXACTAMENTE
 * --------------------
 * PostgreSQL garantiza que no existan dos filas del mismo vuelo con el mismo
 * numero de asiento, siempre que ambas esten en estado que ocupa (bloqueado o
 * asignado). No es codigo de aplicacion: es una regla que el motor aplica DENTRO
 * de la transaccion, en el momento del INSERT o del UPDATE.
 *
 * POR QUE NO HAY VENTANA ENTRE "COMPROBAR" Y "TOMAR"
 * ---------------------------------------------------
 * Con un candado de Redis el flujo son dos pasos en dos sistemas distintos: se
 * comprueba en Redis que el asiento este libre, y despues se escribe en
 * PostgreSQL. Si dos compras pasan el chequeo antes de que ninguna escriba,
 * las dos escriben y las dos ganan: dos personas con el mismo asiento. Ese
 * intervalo es el bug, y con exclusion no existe, porque el INSERT y la
 * verificacion son la misma operacion atomica.
 *
 * POR QUE LA CONDICION ES PARCIAL
 * -------------------------------
 * Con `WHERE (estado IN ('bloqueado', 'asignado'))`, la restriccion solo mira
 * los asientos que compiten. Un asiento liberado (estado `liberado`) deja de
 * bloquear al siguiente: se puede reutilizar y queda el rastro del uso.
 *
 * POR QUE HACE FALTA `btree_gist`
 * ------------------------------
 * `EXCLUDE USING gist` necesita un operador de igualdad para cada columna. El
 * de `uuid` no viene en la extension por defecto: hay que instalar
 * `btree_gist`. Por eso el `CREATE EXTENSION` va aqui, en la misma migracion.
 * Sin ella, la migracion falla en voz alta en vez de dejar la tabla sin
 * proteccion.
 *
 * Ver docs/01, seccion "Bloqueo de asientos".
 */
return new class extends Migration
{
    public function up(): void
    {
        // La extension que hace posible el indice de exclusion sobre uuid.
        DB::unprepared('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('asientos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('vuelo_id');

            // El numero tal como lo imprime la aerolinea: "14A", "3C".
            // Case-sensitive a proposito: la aerolinea distingue mayusculas y
            // "14a" no es "14A".
            $tabla->string('numero', 8);

            $tabla->string('clase', 40)->nullable();

            // Solo estos dos estados compiten entre si. El indice de exclusion
            // de abajo mira unicamente estas filas.
            $tabla->string('estado', 24)->default('disponible');

            $tabla->uuid('reserva_id')->nullable();
            $tabla->uuid('reserva_pasajero_id')->nullable();
            $tabla->uuid('bloqueado_por_usuario_id')->nullable();

            // Bloqueo provisional con vencimiento. Un bloqueo VENCIDO no es un
            // asiento tomado: es uno que nadie esta mirando.
            $tabla->timestampTz('bloqueado_hasta')->nullable();
            $tabla->timestampTz('bloqueado_en')->nullable();

            // Para pintar el mapa de asientos en orden, sin parsear "14A" en
            // PHP (que es donde aparecen los errores: 14A no va antes que 2C,
            // y comparar como texto lo pone despues).
            $tabla->integer('fila_orden')->nullable();
            $tabla->integer('columna_numero')->nullable();

            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
            $tabla->foreign('vuelo_id')->references('id')->on('vuelos')->cascadeOnDelete();
        });

        // Las columnas `reserva_id` y `reserva_pasajero_id` quedan sin clave
        // foranea aqui, porque esas tablas todavia no existen: esta migracion
        // corre antes. Las claves se agregan en las migraciones que crean esas
        // tablas (000009 y 000010), donde si se pueden declarar.

        $this->crearIndiceDeExclusion();
        $this->crearIndicesDeConsulta();
    }

    public function down(): void
    {
        Schema::dropIfExists('asientos');
    }

    /**
     * LA RESTRICCION DE EXCLUSION.
     *
     * Es la razon de ser de esta migracion. Todo lo demas (Redis, el
     * `lockForUpdate`, el candado de serie) sirve para que las colisiones sean
     * raras. Esta restriccion es la que hace que, cuando aparecen, una de las
     * dos compras falle en vez de que las dos salgan con el mismo asiento.
     */
    private function crearIndiceDeExclusion(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.asientos
                ADD CONSTRAINT asientos_no_duplicados
                EXCLUDE USING gist (
                    vuelo_id WITH =,
                    numero WITH =
                )
                WHERE (estado IN ('bloqueado', 'asignado'))
            SQL);
    }

    private function crearIndicesDeConsulta(): void
    {
        // Lectura del mapa de asientos de un vuelo.
        DB::unprepared('CREATE INDEX asientos_por_vuelo ON public.asientos (vuelo_id, fila_orden, columna_numero)');

        // Limpieza periodica de bloqueos vencidos.
        DB::unprepared('CREATE INDEX asientos_bloqueos_vencidos ON public.asientos (bloqueado_hasta) WHERE estado = \'bloqueado\'');

        // Asientos de una reserva.
        DB::unprepared('CREATE INDEX asientos_por_reserva ON public.asientos (reserva_id) WHERE reserva_id IS NOT NULL');
    }
}